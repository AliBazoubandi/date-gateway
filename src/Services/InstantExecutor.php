<?php
declare(strict_types=1);
namespace Services;

use Api\Download;
use Api\Errors;
use Api\Links;
use Core\Config;
use Core\Db;
use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Encryption;
use Services\Logger;
use Worker\ResultWriter;

/**
 * Synchronous execution for response_mode = 'instant'.
 * Respects the per-connection cap with a metadata-DB named lock + counter;
 * returns 429 + Retry-After when the cap is busy (never queues).
 *
 * Results live in BOTH shapes, like delayed executions do: the POST response
 * carries the rows inline (backward compatible), AND the run is streamed to
 * storage/results/{id}.json via ResultWriter so the response can include a
 * per-call download link (and ?download=1 delivers the file directly). The
 * file lifetime is limits.instant.result_ttl_minutes, which also caps the
 * request's cache window; sweep/cleanup handles expiry exactly as for delayed
 * results.
 */
final class InstantExecutor
{
    /** File lifetime of a successful instant result, in minutes. */
    public static function resultTtlMinutes(): int
    {
        return max(1, (int)Config::get('limits.instant.result_ttl_minutes', 60));
    }

    /**
     * The instant cache window a request actually gets, in seconds (0 = off).
     *
     * A cache hit replays the previous run's result FILE, and that file only
     * lives for limits.instant.result_ttl_minutes. A longer window on the
     * request therefore cannot be honoured: the sweep silently turns every
     * hit past the file lifetime into a fresh run. The shorter value wins, so
     * the window the gateway promises is the window it can serve.
     */
    public static function cacheWindowSeconds(array $request): int
    {
        $wanted = (int)($request['instant_cache_seconds'] ?? 0);
        if ($wanted <= 0) {
            return 0;
        }
        return min($wanted, self::resultTtlMinutes() * 60);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $connection */
    public static function handle(
        array $request,
        array $connection,
        array $client,
        string $callId,
        string $ip,
        bool $asFile = false
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

        // The call gets a download token like every delayed call, so Status
        // polling and the response envelope can expose a stable result link.
        $token = Str::hex(24); // 48 hex chars
        $db->execute(
            'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, created_at, ip, was_shared)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
            [$callId, $client['id'], $request['id'], $execId, hash('sha256', $token),
                Encryption::encrypt($token), $now, $ip]
        );
        Logger::event('submitted', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip);

        // Open the result file BEFORE the query and stream rows as they come
        // (no extra memory beyond the inline copy we keep for the response).
        // A storage problem must never fail a successful query: it only
        // leaves the execution without a downloadable file.
        $writer = new ResultWriter();
        $fileOk = true;
        $fileError = '';
        // Same rule on the free-space floor: a nearly full volume costs the
        // download link, not the answer.
        $noRoom = StorageGuard::reason();
        if ($noRoom !== '') {
            $fileOk = false;
            $fileError = 'storage_low: ' . $noRoom;
        } else {
            try {
                Filesystem::prepare();
                $writer->open(Filesystem::resultsDir() . '/' . $execId . '.json');
            } catch (\RuntimeException $e) {
                $fileOk = false;
                $fileError = Str::truncate($e->getMessage(), 1800);
            }
        }

        $sql = (string)$request['sql_text'];
        $timeout = max(1, (int)$request['timeout_seconds']);
        // Rows are buffered in memory to build the inline JSON response; the
        // instant byte cap turns an unbounded result into an honest 413
        // instead of an OOM-killed PHP process (P1-2).
        $inlineMax = max(1, (int)Config::get('limits.instant.max_result_bytes', 16 * 1024 * 1024));
        $inlineBytes = 0;
        // PHP's own max_execution_time (XAMPP default 30s) must never kill
        // the request before the driver-level statement timeout does. Some
        // SAPIs (e.g. safe-mode-era builds) disable the function entirely.
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 10);
        }
        $rows = [];
        try {
            $result = QueryRunner::run(
                $connection,
                $sql,
                $timeout,
                (int)$request['max_rows'],
                static function (array $row) use (&$rows, $writer, &$fileOk, &$fileError, &$inlineBytes, $inlineMax): void {
                    $rows[] = $row;
                    if ($fileOk) {
                        try {
                            $inlineBytes += $writer->writeRow($row);
                        } catch (\RuntimeException $e) {
                            $fileOk = false; // keep serving inline; drop the file
                            $fileError = Str::truncate($e->getMessage(), 1800);
                        }
                    } else {
                        $inlineBytes += strlen((string)json_encode($row));
                    }
                    if ($inlineBytes > $inlineMax) {
                        throw new ResultTooLargeException(
                            "The inline result exceeded the instant limit of {$inlineMax} bytes."
                        );
                    }
                }
            );
        } catch (ResultTooLargeException $e) {
            if ($writer->touched()) {
                $writer->discard(); // remove the partial file
            }
            $db->execute(
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?, error_code = ? WHERE id = ?",
                [Time::now(), Str::truncate($e->getMessage(), 4000), ExecutionError::TOO_LARGE, $execId]
            );
            Logger::event('failed', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
                null, null, Str::truncate($e->getMessage(), 2000));
            Errors::emit(413, 'result_too_large',
                'The result exceeded the instant size limit (' . number_format($inlineMax) . ' bytes). '
                . 'Narrow the query or use a delayed request.');
        }

        if ($result['ok']) {
            self::finalizeReady($request, $callId, $token, $execId, $writer, $result, $rows,
                $fileOk, $fileError, $asFile, (int)$client['id'], $ip);
            return;
        }

        if ($writer->touched()) {
            $writer->discard(); // remove the partial file
        }

        if ($result['rejected']) {
            // The stored SQL is not read-only: it never reached the target.
            $db->execute(
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?, error_code = ? WHERE id = ?",
                [Time::now(), Str::truncate($result['error'], 4000), ExecutionError::REJECTED, $execId]
            );
            Logger::event('failed', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
                $result['duration_ms'], null, 'query_rejected: ' . Str::truncate($result['error'], 1900));
            Errors::emit(502, 'query_rejected',
                'The stored query was rejected by the gateway read-only policy. Contact the gateway administrator.');
        }

        if ($result['timed_out']) {
            $db->execute(
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?, error_code = ? WHERE id = ?",
                [Time::now(), Str::truncate($result['error'], 4000), ExecutionError::TIMEOUT, $execId]
            );
            Logger::event('timeout', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
                $result['duration_ms'], null, 'query exceeded timeout_seconds');
            Errors::emit(504, 'timeout',
                'The query did not finish within ' . (int)$request['timeout_seconds'] . ' seconds on the gateway.');
        }

        $db->execute(
            "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?, error_code = ? WHERE id = ?",
            [Time::now(), Str::truncate($result['error'], 4000), ExecutionError::FAILED, $execId]
        );
        Logger::event('failed', (int)$client['id'], (int)$request['id'], $callId, $execId, $ip,
            $result['duration_ms'], null, Str::truncate($result['error'], 2000));
        Errors::emit(502, 'execution_failed',
            'The query failed on the gateway. Details were recorded in the gateway logs.');
    }

    /**
     * Marks a successful instant run ready, writes the result envelope file
     * and emits the response (inline JSON, or the file itself for ?download=1).
     *
     * @param array{ok:bool,row_count:int,truncated:bool,duration_ms:int,error:string,timed_out:bool,rejected:bool} $result
     * @param list<array<string,mixed>> $rows
     */
    private static function finalizeReady(
        array $request,
        string $callId,
        string $token,
        int $execId,
        ResultWriter $writer,
        array $result,
        array $rows,
        bool $fileOk,
        string $fileError,
        bool $asFile,
        int $clientId,
        string $ip
    ): void {
        $db = Db::instance();
        $readyAt = Time::now();

        if ($fileOk) {
            try {
                $writer->finish([
                    'mode' => 'instant',
                    'execution_id' => $execId,
                    'request_code' => (string)$request['code'],
                    'row_count' => $result['row_count'],
                    'truncated' => (bool)$result['truncated'],
                    'duration_ms' => $result['duration_ms'],
                    'executed_at' => Time::iso($readyAt),
                ]);
            } catch (\RuntimeException $e) {
                $writer->discard();
                $fileOk = false;
                $fileError = Str::truncate($e->getMessage(), 1800);
            }
        }

        $resultPath = null;
        $resultSize = null;
        $expiresAt = null;
        if ($fileOk) {
            $resultPath = 'results/' . $execId . '.json';
            $resultSize = (@filesize(Filesystem::resultsDir() . '/' . $execId . '.json') ?: null);
            $ttl = self::resultTtlMinutes();
            $expiresAt = Time::mysql(Time::addMinutes(new \DateTimeImmutable($readyAt, Time::timezone()), $ttl));
        }

        $db->execute(
            'UPDATE executions SET status = ?, finished_at = ?, ready_at = ?, expires_at = ?,
                    result_path = ?, result_size_bytes = ?, row_count = ?, truncated = ?
             WHERE id = ?',
            ['ready', $readyAt, $readyAt, $expiresAt, $resultPath, $resultSize,
                $result['row_count'], $result['truncated'] ? 1 : 0, $execId]
        );
        $message = $result['truncated'] ? 'truncated at max_rows' : 'ok';
        if ($fileError !== '') {
            $message .= '; result file unavailable: ' . $fileError;
        }
        Logger::event('instant_executed', $clientId, (int)$request['id'], $callId, $execId, $ip,
            $result['duration_ms'], $result['row_count'], $message);

        if ($asFile) {
            if (!$fileOk) {
                Errors::emit(502, 'result_unavailable',
                    'The gateway could not store the result file. Retry without ?download=1.');
            }
            $exec = $db->one('SELECT * FROM executions WHERE id = ?', [$execId]);
            if ($exec !== null) {
                Download::streamResult($exec, $callId, $ip, 'result delivered (direct via ?download=1)');
            }
            return;
        }

        self::emitReady($callId, $readyAt, $result['row_count'], $result['truncated'], $rows,
            $fileOk ? Links::download($token) : null);
    }

    /** Replays a cached instant execution (fresh enough; not yet expired). */
    public static function emitFromCache(array $request, int $clientId, string $callId, array $execution, string $ip, bool $asFile = false): void
    {
        $file = null;
        if ($execution['result_path'] !== null) {
            try {
                $candidate = Filesystem::resultPath((string)$execution['result_path']);
                if (is_file($candidate)) {
                    $file = $candidate;
                }
            } catch (\RuntimeException) {
                $file = null; // tampered stored path
            }
        }
        if ($file === null) {
            // The cached run has nothing replayable. An empty 200 would lie
            // to the client (row_count says otherwise) — refuse honestly (P1-3).
            Logger::event('failed', $clientId, (int)$request['id'], $callId, (int)$execution['id'], $ip,
                null, null, 'cache replay but no stored result file');
            Errors::emit(502, 'result_unavailable',
                'The gateway could not provide the cached result. Retry the request.');
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        $rows = [];
        // Newer executions store the standard {"data":[...],"meta":{...}}
        // envelope; older ones (pre-file era) stored a bare rows array.
        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            if (!is_array($decoded['data'])) {
                self::cacheCorrupt($request, $clientId, $callId, $execution, $ip);
            }
            $rows = $decoded['data'];
        } elseif (is_array($decoded)) {
            $rows = $decoded;
        } else {
            self::cacheCorrupt($request, $clientId, $callId, $execution, $ip);
        }

        if ($asFile) {
            Download::streamResult($execution, $callId, $ip, 'result delivered (direct via ?download=1, cached run)');
            return;
        }

        Logger::event('instant_cache_hit', $clientId, (int)$request['id'], $callId, (int)$execution['id'], $ip,
            null, (int)$execution['row_count'], 'replayed cached instant result');

        $link = Links::forCall($callId);
        self::emitReady($callId, null, (int)$execution['row_count'], (int)$execution['truncated'] === 1, $rows, $link);
    }

    /** @return never */
    private static function cacheCorrupt(array $request, int $clientId, string $callId, array $execution, string $ip): void
    {
        Logger::event('failed', $clientId, (int)$request['id'], $callId, (int)$execution['id'], $ip,
            null, null, 'cache replay: stored result file is not valid JSON');
        Errors::emit(502, 'result_unavailable',
            'The stored cached result is unreadable. Retry the request.');
    }

    private static function emitReady(string $callId, ?string $executedAt, int $rowCount, bool $truncated, array $rows, ?string $link): void
    {
        json_out([
            'call_id' => $callId,
            'mode' => 'instant',
            'status' => 'ready',
            'executed_at' => $executedAt === null ? null : Time::iso($executedAt),
            'row_count' => $rowCount,
            'truncated' => $truncated,
            'link' => $link,
            'data' => $rows,
        ]);
    }
}
