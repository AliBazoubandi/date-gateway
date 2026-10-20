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
use Services\ExecutionError;
use Services\InstantExecutor;
use Services\Logger;

/**
 * POST /api/v1/request/{code} — the submission endpoint.
 * Auth flow: Bearer key -> active client -> permission for this request ->
 * request active. Async instant replay rules below.
 * An Idempotency-Key header makes a retried submission answer from the call it
 * already created instead of running the query a second time.
 */
final class Submit
{
    /** Client-facing HTTP status per sanitized failure code (an instant POST
     *  that failed answered with one of these; a replay must answer the same). */
    private const FAILURE_HTTP = [
        ExecutionError::TIMEOUT => 504,
        ExecutionError::TOO_LARGE => 413,
    ];

    /** Retry-After (seconds) on a 409: the in-flight run's next poll is cheap. */
    private const IN_PROGRESS_RETRY_AFTER = '5';

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

        // ?download=1: the instant POST response is the result FILE itself
        // (Content-Disposition attachment) instead of the JSON envelope.
        $asFile = (string)Http::param('download', 'g') === '1';
        $idemHash = self::idempotencyKeyHash();
        if ($idemHash !== null) {
            // The lock spans the replay lookup AND this request's own call
            // INSERT: the flows below write the key hash into calls before they
            // emit, so a second submit with the same key cannot read "no prior
            // call" and start a duplicate run. It dies with the connection.
            $db->lock('gw_idem_' . $clientId . '_' . substr($idemHash, 0, 16), 10);
            // A prior call that cannot be replayed YET is an in-flight instant
            // run: answering 409 is the only correct move. Falling through used
            // to start a second execution and a second call for one logical
            // submission whenever the first run outlived the 10 s lock wait.
            if (self::replayPriorSubmission($request, $client, $idemHash, $ip, $asFile)) {
                Errors::emit(409, 'submission_in_progress',
                    'A submission with this Idempotency-Key is still running. Retry shortly.',
                    ['Retry-After' => self::IN_PROGRESS_RETRY_AFTER]);
            }
        }

        self::enforceClientSubmitRate($clientId, $requestId, $ip);

        $callId = self::newCallId();

        if ((string)$request['response_mode'] === 'instant') {
            self::instantFlow($request, $client, $callId, $ip, $asFile, $idemHash);
            return;
        }
        self::delayedFlow($request, $client, $callId, $ip, $idemHash);
    }

    /** ---------------------------------------------------------------- */

    /**
     * SHA-256 of the client's Idempotency-Key header, or null when absent.
     * The header value itself is never stored. 8 characters minimum because a
     * one-letter key is a collision between unrelated submissions waiting to
     * happen; printable ASCII only, so a mangled or multi-line value is a
     * client error rather than a silently shared bucket.
     */
    private static function idempotencyKeyHash(): ?string
    {
        $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '') {
            return null;
        }
        if (strlen($key) < 8 || strlen($key) > 200 || preg_match('/^[!-~]+$/', $key) !== 1) {
            Errors::emit(400, 'invalid_idempotency_key',
                'Idempotency-Key must be 8-200 printable ASCII characters.');
        }
        return hash('sha256', $key);
    }

    /**
     * A key this client already used for this request answers the retry from
     * the call it created: same call_id, same execution, no second run.
     *
     * @return bool true when a prior call exists but has nothing to hand back
     *         yet — an instant run that is still `scheduled`/`running`. The
     *         caller answers 409 `submission_in_progress` and starts nothing;
     *         returning false means "no prior call, proceed with this submit".
     *         Every replayable state emits its answer here and never returns.
     */
    private static function replayPriorSubmission(array $request, array $client, string $idemHash, string $ip, bool $asFile): bool
    {
        $clientId = (int)$client['id'];
        $requestId = (int)$request['id'];
        $prior = Db::instance()->one(
            'SELECT e.*, c.id AS call_id FROM calls c
             JOIN executions e ON e.id = c.execution_id
             WHERE c.client_id = ? AND c.request_id = ? AND c.idempotency_key_hash = ?
             ORDER BY c.created_at DESC LIMIT 1',
            [$clientId, $requestId, $idemHash]
        );
        if ($prior === null) {
            return false;
        }
        $callId = (string)$prior['call_id'];
        $execId = (int)$prior['id'];
        $status = (string)$prior['status'];
        // Same clock rule as the status endpoint: a ready row past its TTL is
        // no longer servable even if cleanup has not marked it yet.
        if ($status === 'ready' && $prior['expires_at'] !== null
            && (string)$prior['expires_at'] <= Time::now()) {
            $status = 'expired';
        }
        $inFlight = (string)$prior['mode'] === 'instant'
            && ($status === 'scheduled' || $status === 'running');
        if ($inFlight) {
            // Not a replay: a retry must not run the query a second time. No
            // 'idempotent_replay' row either — nothing was replayed.
            return true;
        }
        Logger::event('idempotent_replay', $clientId, $requestId, $callId, $execId, $ip,
            null, $prior['row_count'] !== null ? (int)$prior['row_count'] : null,
            'replayed the submission this Idempotency-Key already made (' . $status . ')');

        if ((string)$prior['mode'] === 'instant') {
            if ($status === 'ready') {
                InstantExecutor::emitFromCache($request, $clientId, $callId, $prior, $ip, $asFile);
            }
            if ($status === 'expired') {
                Errors::gone('That submission\'s result has expired from the gateway. Submit again with a new Idempotency-Key.');
            }
            if ($status === 'failed') {
                $errorCode = ExecutionError::code($prior['error_code'] ?? null);
                Errors::emit(self::FAILURE_HTTP[$errorCode] ?? 502, $errorCode,
                    ExecutionError::message($errorCode));
            }
            return false; // unreachable: every instant status above emits
        }

        json_out([
            'call_id' => $callId,
            'status' => $status,
            'scheduled_at' => $prior['scheduled_at'] !== null ? Time::iso((string)$prior['scheduled_at']) : null,
            'ready_at' => $prior['ready_at'] !== null ? Time::iso((string)$prior['ready_at']) : null,
            'expires_at' => $prior['expires_at'] !== null ? Time::iso((string)$prior['expires_at']) : null,
            'link' => $status === 'ready' ? Links::forCall($callId) : null,
            'error' => ExecutionError::forExecution(['status' => $status, 'error_code' => $prior['error_code'] ?? null]),
        ]);
    }

    private static function instantFlow(array $request, array $client, string $callId, string $ip, bool $asFile, ?string $idemHash): void
    {
        $db = Db::instance();

        $cacheSeconds = InstantExecutor::cacheWindowSeconds($request);
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
                    'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, idempotency_key_hash, created_at, ip, was_shared) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [$callId, $client['id'], $request['id'], $cached['id'], hash('sha256', $token),
                        Encryption::encrypt($token), $idemHash, Time::now(), $ip]
                );
                Logger::event('submitted', (int)$client['id'], (int)$request['id'], $callId, (int)$cached['id'], $ip);
                InstantExecutor::emitFromCache($request, (int)$client['id'], $callId, $cached, $ip, $asFile);
                return;
            }
        }
        $connection = $db->one('SELECT * FROM connections WHERE id = ?', [$request['connection_id']]);
        InstantExecutor::handle($request, $connection, $client, $callId, $ip, $asFile, $idemHash);
    }

    /**
     * Cache rule for delayed requests:
     *  1. scheduled|running execution exists -> attach to it (was_shared=true)
     *  2. ready and not expired -> attach + return link now
     *  3. else create a new execution scheduled_at = now + delay_minutes
     */
    private static function delayedFlow(array $request, array $client, string $callId, string $ip, ?string $idemHash): void
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
            self::attachCall($request, $client, $callId, $ip, (int)$active['id'], 1, $idemHash);
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
                'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, idempotency_key_hash, created_at, ip, was_shared)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$callId, $client['id'], $request['id'], $ready['id'], hash('sha256', $token),
                    Encryption::encrypt($token), $idemHash, Time::now(), $ip]
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
        // Execution and call commit together: a scheduled row with no call
        // attached is a real query run for nobody (and the worker claims it),
        // while a call with no execution can never be answered.
        $execId = $db->transaction(static function () use ($db, $request, $scheduledAt, $client, $callId, $ip, $idemHash): int {
            $db->execute(
                'INSERT INTO executions (request_id, mode, status, scheduled_at) VALUES (?, ?, ?, ?)',
                [(int)$request['id'], 'delayed', 'scheduled', $scheduledAt]
            );
            $newId = $db->insertId();
            self::attachCall($request, $client, $callId, $ip, $newId, 0, $idemHash);
            return $newId;
        });
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

    private static function attachCall(array $request, array $client, string $callId, string $ip, ?int $executionId, int $wasShared, ?string $idemHash): void
    {
        // Every delayed call gets its own download token at creation: the
        // SHA-256 hash for lookup, an AES-256-GCM copy so the webhook and the
        // status endpoint can rebuild a stable link once the execution is ready.
        $token = Str::hex(24); // 48 hex chars
        Db::instance()->execute(
            'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, idempotency_key_hash, created_at, ip, was_shared)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$callId, $client['id'], $request['id'], $executionId,
                hash('sha256', $token), Encryption::encrypt($token), $idemHash, Time::now(), $ip, $wasShared]
        );
    }

    /**
     * Optional per-client submit throttle (limits.client_submit_max; 0 = off).
     * One atomic upsert of this client's fixed-window counter per submit, so
     * concurrent submits cannot all read a stale count and slip past the cap
     * together. Over the cap: 429 with the real seconds left in the window.
     */
    private static function enforceClientSubmitRate(int $clientId, int $requestId, string $ip): void
    {
        $cap = (int)Config::get('limits.client_submit_max', 0);
        if ($cap <= 0) {
            return;
        }
        $window = max(1, (int)Config::get('limits.client_submit_window_seconds', 60));
        $db = Db::instance();

        // The hits assignment comes FIRST because both IF() conditions must
        // still see the stored window_start (MySQL/MariaDB evaluate ON
        // DUPLICATE KEY UPDATE assignments left to right, already-assigned
        // columns read their new value). LAST_INSERT_ID(expr) publishes this
        // submit's own counter without a follow-up read, which concurrent
        // submits could otherwise inflate past what they actually caused.
        $db->execute(
            'INSERT INTO client_rate_limits (client_id, window_start, hits)
             VALUES (?, NOW(), 1)
             ON DUPLICATE KEY UPDATE
               hits = LAST_INSERT_ID(IF(window_start <= DATE_SUB(NOW(), INTERVAL ? SECOND), 1, hits + 1)),
               window_start = IF(window_start <= DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), window_start)',
            [$clientId, $window, $window]
        );
        // insert_id is 0 on the fresh-row insert (no AUTO_INCREMENT here):
        // that submit is hit #1.
        $hits = max(1, $db->insertId());
        if ($hits <= $cap) {
            return;
        }

        Logger::event('rate_limited', $clientId, $requestId, null, null, $ip, null, null,
            "client submit cap reached ({$hits} in {$window}s)");
        $age = (int)$db->value(
            'SELECT TIMESTAMPDIFF(SECOND, window_start, NOW()) FROM client_rate_limits WHERE client_id = ?',
            [$clientId]
        );
        Errors::rateLimited(max(1, min($window, $window - $age)));
    }

    private static function newCallId(): string
    {
        return Str::hex(16); // 32 hex chars
    }
}
