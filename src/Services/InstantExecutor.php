<?php
declare(strict_types=1);
namespace Services;

use Api\Errors;
use Api\Links;
use Core\Db;
use Core\Filesystem;
use Core\Str;
use Core\Time;

/**
 * Synchronous execution for response_mode = 'instant'.
 * Respects the per-connection cap with a metadata-DB named lock + counter;
 * returns 429 + Retry-After when the cap is busy (never queues).
 */
final class InstantExecutor
{
    /** @param array<string,mixed> $request @param array<string,mixed> $connection */
    public static function handle(
        array $request,
        array $connection,
        array $client,
        string $callId,
        string $ip
    ): void {
        $db = Db::instance();
        $connId = (int)$connection['id'];
        $cap = (int)($connection['max_concurrency'] ?? 0) > 0
            ? (int)$connection['max_concurrency']
            : (int)\Core\Config::get('limits.connection_concurrency', 2);

        $lockName = 'gw_inst:' . $connId;
        if (!$db->lock($lockName, 1)) {
            Logger::event('rate_limited', (int)$client['id'], (int)$request['id'], $callId, null, $ip, null, null,
                'instant cap lock busy');
            Errors::rateLimited((int)\Core\Config::get('limits.rate_limited_retry_after', 5));
        }

        $running = (int)$db->value(
            "SELECT COUNT(*) FROM executions e
             JOIN requests r ON r.id = e.request_id
             WHERE r.connection_id = ? AND e.mode = 'instant' AND e.status = 'running'",
            [$connId]
        );
        if ($running >= $cap) {
            $db->unlock($lockName);
            Logger::event('rate_limited', (int)$client['id'], (int)$request['id'], $callId, null, $ip, null, null,
                "conn {$connId} at cap {$cap}");
            Errors::rateLimited((int)\Core\Config::get('limits.rate_limited_retry_after', 5));
        }

        $now = Time::now();
        $db->execute(
            "INSERT INTO executions
                (request_id, mode, status, scheduled_at, started_at, row_count, truncated)
             VALUES (?, 'instant', 'running', ?, ?, 0, 0)",
            [$request['id'], $now, $now]
        );
        $execId = $db->insertId();
        $db->unlock($lockName);

        $db->execute(
            'INSERT INTO calls (id, client_id, request_id, execution_id, created_at, ip, was_shared) VALUES (?, ?, ?, ?, ?, ?, 0)',
            [$callId, $client['id'], $request['id'], $execId, $now, $ip]
        );
        Logger::event('submitted', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip);

        $sql = (string)$request['sql_text'];
        $timeout = max(1, (int)$request['timeout_seconds']);
        // PHP's own max_execution_time (XAMPP default 30s) must never kill
        // the request before the driver-level statement timeout does.
        @set_time_limit($timeout + 10);
        $rows = [];
        $result = QueryRunner::run(
            $connection,
            $sql,
            $timeout,
            (int)$request['max_rows'],
            static function (array $row) use (&$rows): void {
                $rows[] = $row;
            }
        );

        if ($result['ok']) {
            $readyAt = Time::now();
            $expiresAt = null;
            $resultPath = null;
            $resultSize = null;
            $cacheSeconds = ((int)$request['instant_cache_seconds'] > 0 && (string)$request['response_mode'] === 'instant')
                ? (int)$request['instant_cache_seconds']
                : 0;
            if ($cacheSeconds > 0) {
                try {
                    Filesystem::prepare();
                    $path = Filesystem::cacheDir() . '/' . $execId . '.json';
                    $json = Str::json($rows);
                    if (file_put_contents($path, $json, LOCK_EX) !== false) {
                        $resultPath = Filesystem::relative($path);
                        $resultSize = filesize($path) ?: null;
                        $expiresAt = Time::mysql(Time::addSeconds(new \DateTimeImmutable($now, Time::timezone()), $cacheSeconds));
                    }
                } catch (\RuntimeException) {
                    // cache write failed; serve without cache copy
                }
            }
            $db->execute(
                'UPDATE executions SET status = ?, finished_at = ?, ready_at = ?, expires_at = ?,
                        result_path = ?, result_size_bytes = ?, row_count = ?, truncated = ?
                 WHERE id = ?',
                ['ready', $readyAt, $readyAt, $expiresAt, $resultPath, $resultSize,
                    $result['row_count'], $result['truncated'] ? 1 : 0, $execId]
            );
            Logger::event('instant_executed', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
                $result['duration_ms'], $result['row_count'],
                $result['truncated'] ? 'truncated at max_rows' : 'ok');

            self::emitReady($callId, $readyAt, $result['row_count'], $result['truncated'], $rows);
            return;
        }

        if ($result['timed_out']) {
            $db->execute(
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ? WHERE id = ?",
                [Time::now(), Str::truncate($result['error'], 4000), $execId]
            );
            Logger::event('timeout', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
                $result['duration_ms'], null, 'query exceeded timeout_seconds');
            Errors::emit(504, 'timeout',
                'The query did not finish within ' . (int)$request['timeout_seconds'] . ' seconds on the gateway.');
        }

        $db->execute(
            "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ? WHERE id = ?",
            [Time::now(), Str::truncate($result['error'], 4000), $execId]
        );
        Logger::event('failed', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
            $result['duration_ms'], null, Str::truncate($result['error'], 2000));
        Errors::emit(502, 'execution_failed',
            'The query failed on the gateway. Details were recorded in the gateway logs.');
    }

    /** Replays a cached instant execution (fresh enough; not yet expired). */
    public static function emitFromCache(array $request, int $clientId, string $callId, array $execution, string $ip): void
    {
        $path = Filesystem::absoluteFromRelative((string)$execution['result_path']);
        $rows = [];
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $rows = $decoded;
            }
        }
        Logger::event('instant_cache_hit', $clientId, (int)$request['id'], $callId, (int)$execution['id'], $ip,
            null, (int)$execution['row_count'], 'replayed cached instant result');
        self::emitReady($callId, null, (int)$execution['row_count'], (int)$execution['truncated'] === 1, $rows);
    }

    private static function emitReady(string $callId, ?string $executedAt, int $rowCount, bool $truncated, array $rows): void
    {
        json_out([
            'call_id' => $callId,
            'mode' => 'instant',
            'status' => 'ready',
            'executed_at' => $executedAt === null ? null : Time::iso($executedAt),
            'row_count' => $rowCount,
            'truncated' => $truncated,
            'data' => $rows,
        ]);
    }
}
