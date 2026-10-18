<?php
declare(strict_types=1);
namespace Api;

use Api\Errors;
use Core\Config;
use Core\Db;
use Core\Http;
use Core\Str;
use Core\Time;
use Services\Encryption;
use Services\InstantExecutor;
use Services\Logger;

/**
 * POST /api/v1/request/{code} — the submission endpoint.
 * Auth flow: Bearer key -> active client -> permission for this request ->
 * request active. Async instant replay rules below.
 */
final class Submit
{
    public static function handle(string $code): void
    {
        if (!Http::isPost()) {
            Errors::emit(405, 'method_not_allowed', 'Use POST for this endpoint.', ['Allow' => 'POST']);
        }

        $client = ApiAuth::requireClient();
        $clientId = (int)$client['id'];
        $db = Db::instance();
        $ip = client_ip();

        // Unknown/inactive request -> 404 with a logged 'denied' event.
        $request = $db->one(
            'SELECT r.*, c.db_type, c.max_concurrency
             FROM requests r JOIN connections c ON c.id = r.connection_id
             WHERE r.code = ?',
            [$code]
        );
        if ($request === null || (int)$request['is_active'] !== 1
            || (int)$db->one('SELECT is_active FROM connections WHERE id = ?', [$request['connection_id']])['is_active'] !== 1) {
            $requestId = $request !== null ? (int)$request['id'] : null;
            Logger::event('denied', $clientId, $requestId, null, null, $ip, null, null,
                $request === null ? 'unknown request code' : 'request or connection inactive');
            Errors::notFound('Unknown request code.');
        }
        $requestId = (int)$request['id'];

        if ($db->value('SELECT COUNT(*) FROM client_request_permissions WHERE client_id = ? AND request_id = ?', [$clientId, $requestId]) == 0) {
            Logger::event('denied', $clientId, $requestId, null, null, $ip, null, null, 'permission missing');
            Errors::forbidden('This client has no permission for this request.');
        }

        self::enforceClientSubmitRate($clientId, $requestId, $ip);

        $callId = self::newCallId();

        if ((string)$request['response_mode'] === 'instant') {
            self::instantFlow($request, $client, $callId, $ip);
            return;
        }
        self::delayedFlow($request, $client, $callId, $ip);
    }

    /** ---------------------------------------------------------------- */

    private static function instantFlow(array $request, array $client, string $callId, string $ip): void
    {
        $db = Db::instance();
        // ?download=1: the POST response is the result FILE itself
        // (Content-Disposition attachment) instead of the JSON envelope.
        $asFile = (string)\Core\Http::param('download', 'g') === '1';

        $cacheSeconds = (int)$request['instant_cache_seconds'];
        if ($cacheSeconds > 0) {
            $cached = $db->one(
                "SELECT * FROM executions
                 WHERE request_id = ? AND mode = 'instant' AND status = 'ready'
                   AND result_path IS NOT NULL
                   AND finished_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                 ORDER BY id DESC
                 LIMIT 1",
                [$request['id'], $cacheSeconds]
            );
            if ($cached !== null) {
                // The call attaches to the cached execution (was_shared = true)
                // and gets its own download token, same as delayed rule 2.
                $token = \Core\Str::hex(24); // 48 hex chars
                $db->execute(
                    'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, created_at, ip, was_shared) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [$callId, $client['id'], $request['id'], $cached['id'], hash('sha256', $token),
                        Encryption::encrypt($token), Time::now(), $ip]
                );
                Logger::event('submitted', (int)$client['id'], (int)$request['id'], $callId, (int)$cached['id'], $ip);
                InstantExecutor::emitFromCache($request, (int)$client['id'], $callId, $cached, $ip, $asFile);
                return;
            }
        }
        $connection = $db->one('SELECT * FROM connections WHERE id = ?', [$request['connection_id']]);
        InstantExecutor::handle($request, $connection, $client, $callId, $ip, $asFile);
    }

    /**
     * Cache rule for delayed requests:
     *  1. scheduled|running execution exists -> attach to it (was_shared=true)
     *  2. ready and not expired -> attach + return link now
     *  3. else create a new execution scheduled_at = now + delay_minutes
     */
    private static function delayedFlow(array $request, array $client, string $callId, string $ip): void
    {
        $db = Db::instance();
        $now = Time::nowDt();
        $requestId = (int)$request['id'];

        // GET_LOCK serializes the attach-or-create decision per request: two
        // simultaneous first submits must not both fall through to rule 3 and
        // duplicate the run. The lock dies with the connection, so a crash
        // cannot strand it. On lock timeout we proceed unlocked — the same
        // race window as before, only reachable under pathological DB stalls.
        $lockKey = 'gw_submit_' . $requestId;
        $locked = $db->lock($lockKey, 10);
        $release = static function () use ($db, $lockKey, $locked): void {
            if ($locked) {
                $db->unlock($lockKey);
            }
        };

        $active = $db->one(
            "SELECT * FROM executions
             WHERE request_id = ? AND status IN ('scheduled', 'running')
             ORDER BY id DESC
             LIMIT 1",
            [$request['id']]
        );
        if ($active !== null) {
            self::attachCall($request, $client, $callId, $ip, $active, 1);
            $status = (string)$active['status'];
            Logger::event('shared', (int)$client['id'], $requestId, $callId, (int)$active['id'], $ip,
                null, null, 'attached to ' . $status . ' execution (cache rule 1, link arrives with the webhook)');
            $release();
            json_out([
                'call_id' => $callId,
                'status' => $status,
                'scheduled_at' => $active['scheduled_at'] !== null ? Time::iso((string)$active['scheduled_at']) : null,
                'ready_at' => $active['ready_at'] !== null ? Time::iso((string)$active['ready_at']) : null,
                'expires_at' => $active['expires_at'] !== null ? Time::iso((string)$active['expires_at']) : null,
                'link' => null,
            ]);
        }

        $ready = $db->one(
            "SELECT * FROM executions
             WHERE request_id = ? AND status = 'ready' AND expires_at > NOW()
             ORDER BY id DESC
             LIMIT 1",
            [$request['id']]
        );
        if ($ready !== null) {
            // Rule 2: attach + return a usable link NOW. Only the SHA-256 of
            // the download token is stored for lookup, plus an AES-256-GCM
            // copy so the webhook and the status endpoint can rebuild the
            // same link later (hash-only would make that impossible).
            $token = Str::hex(24); // 48 hex chars
            $db->execute(
                'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, created_at, ip, was_shared)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$callId, $client['id'], $request['id'], $ready['id'], hash('sha256', $token),
                    Encryption::encrypt($token), Time::now(), $ip]
            );
            Logger::event('shared', (int)$client['id'], $requestId, $callId, (int)$ready['id'], $ip,
                null, (int)$ready['row_count'], 'attached to ready execution (cache rule 2, fresh call token)');
            $release();
            json_out([
                'call_id' => $callId,
                'status' => 'ready',
                'ready_at' => Time::iso((string)$ready['ready_at']),
                'expires_at' => Time::iso((string)$ready['expires_at']),
                'link' => Links::download($token),
            ]);
        }

        // Rule 3: new execution.
        $scheduledAt = Time::mysql(Time::addMinutes($now, (int)$request['delay_minutes']));
        $db->execute(
            "INSERT INTO executions (request_id, mode, status, scheduled_at) VALUES (?, 'delayed', 'scheduled', ?)",
            [$request['id'], $scheduledAt]
        );
        $execId = $db->insertId();
        $exec = $db->one('SELECT * FROM executions WHERE id = ?', [$execId]);
        self::attachCall($request, $client, $callId, $ip, $exec, 0);
        Logger::event('submitted', (int)$client['id'], $requestId, $callId, $execId, $ip,
            null, null, 'new execution scheduled for ' . $scheduledAt);
        $release();
        json_out([
            'call_id' => $callId,
            'status' => 'scheduled',
            'scheduled_at' => Time::iso($scheduledAt),
            'ready_at' => null,
            'expires_at' => null,
            'link' => null,
        ]);
    }

    private static function attachCall(array $request, array $client, string $callId, string $ip, ?array $exec, int $wasShared): void
    {
        // Every delayed call gets its own download token at creation: the
        // SHA-256 hash for lookup, an AES-256-GCM copy so the webhook and the
        // status endpoint can rebuild a stable link once the execution is ready.
        $token = Str::hex(24); // 48 hex chars
        Db::instance()->execute(
            'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, created_at, ip, was_shared)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$callId, $client['id'], $request['id'], $exec !== null ? $exec['id'] : null,
                hash('sha256', $token), Encryption::encrypt($token), Time::now(), $ip, $wasShared]
        );
    }

    /**
     * Optional per-client submit throttle (limits.client_submit_max; 0 = off).
     * Counts this client's own recent 'submitted'/'shared' log rows in a
     * sliding window; at/over the cap the submission is refused with 429.
     */
    private static function enforceClientSubmitRate(int $clientId, int $requestId, string $ip): void
    {
        $cap = (int)Config::get('limits.client_submit_max', 0);
        if ($cap <= 0) {
            return;
        }
        $window = max(1, (int)Config::get('limits.client_submit_window_seconds', 60));
        $recent = (int)Db::instance()->value(
            "SELECT COUNT(*) FROM request_logs
             WHERE client_id = ? AND event IN ('submitted','shared')
               AND `timestamp` >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$clientId, $window]
        );
        if ($recent >= $cap) {
            Logger::event('rate_limited', $clientId, $requestId, null, null, $ip, null, null,
                "client submit cap reached ({$recent} in {$window}s)");
            Errors::rateLimited($window);
        }
    }

    private static function newCallId(): string
    {
        return Str::hex(16); // 32 hex chars
    }
}
