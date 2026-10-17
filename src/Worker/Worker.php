<?php
declare(strict_types=1);
namespace Worker;

use Core\Config;
use Core\Db;
use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Logger;
use Services\QueryRunner;

/**
 * Delayed-execution worker: moves executions scheduled -> running -> ready|failed.
 *
 * Concurrency model:
 *  - Exactly one worker pass runs at a time (file lock + metadata-DB GET_LOCK,
 *    enforced by the CLI wrapper).
 *  - Claims are atomic: UPDATE ... WHERE status = 'scheduled' (InnoDB row lock).
 *  - The per-connection cap (connections.max_concurrency, else config default 2)
 *    limits concurrently RUNNING delayed executions; the pass is sequential, so
 *    the cap is maintained as a DB-side guard and stays valid if the worker is
 *    ever parallelized or several hosts share one metadata DB.
 *
 * Crash policy:
 *  - 'running' rows older than config worker.stale_running_minutes are leftovers
 *    from an interrupted worker and are failed (partial file deleted).
 *  - Scheduled executions of deactivated requests/connections are failed (they
 *    would otherwise stay stuck and keep winning cache rule 1 forever).
 *  - No automatic retries: a failed execution stays failed; clients resubmit.
 *
 * Success flow (per execution): stream rows to storage/results/{id}.json, mark
 * ready with ready_at/expires_at = ready_at + request ttl_minutes, set
 * result_path/size/row_count/truncated, mint the download token (hash only).
 * Webhook deliveries for the attached calls are enqueued by the worker as
 * 'pending' rows, to be sent by the webhook deliverer.
 */
final class Worker
{
    /** @return array{due:int,ready:int,failed:int,blocked:int,deferred:int,cleaned_stale:int,cleaned_inactive:int} */
    public static function run(): array
    {
        Filesystem::prepare();
        $stats = [
            'due' => 0, 'ready' => 0, 'failed' => 0, 'blocked' => 0, 'deferred' => 0,
            'cleaned_stale' => 0, 'cleaned_inactive' => 0,
        ];

        // Housekeeping first, so neither kind can later win a claim.
        $stats['cleaned_inactive'] = self::failScheduledOnInactive();
        $stats['cleaned_stale'] = self::recoverStaleRunning();

        $db = Db::instance();
        $due = $db->rows(
            "SELECT e.id, r.connection_id
             FROM executions e
             JOIN requests r ON r.id = e.request_id
             JOIN connections c ON c.id = r.connection_id
             WHERE e.mode = 'delayed' AND e.status = 'scheduled' AND e.scheduled_at <= NOW()
               AND r.is_active = 1 AND c.is_active = 1
             ORDER BY e.scheduled_at ASC, e.id ASC
             LIMIT 200"
        );
        $stats['due'] = count($due);
        if ($due === []) {
            return $stats;
        }

        $maxPerRun = max(1, (int)Config::get('worker.max_per_run', 50));
        $running = self::runningCountByConnection();
        $caps = [];
        $processed = 0;

        foreach ($due as $row) {
            if ($processed >= $maxPerRun) {
                // Cap the pass; everything left stays due for the next run.
                $stats['deferred'] = max(0, count($due) - $stats['ready'] - $stats['failed'] - $stats['blocked']);
                break;
            }
            $connId = (int)$row['connection_id'];
            if (!isset($running[$connId])) {
                $running[$connId] = 0;
            }
            if (!isset($caps[$connId])) {
                $caps[$connId] = self::capFor($connId);
            }
            if ($running[$connId] >= $caps[$connId]) {
                $stats['blocked']++; // this connection is at its concurrency cap
                continue;
            }
            if (!self::claim((int)$row['id'])) {
                continue; // lost the atomic claim (should not happen under the fence)
            }
            $running[$connId]++;
            self::executeClaimed((int)$row['id']) ? $stats['ready']++ : $stats['failed']++;
            $running[$connId]--;
            $processed++;
        }
        return $stats;
    }

    // ------------------------------------------------------------------
    // Housekeeping sweeps
    // ------------------------------------------------------------------

    /** Scheduled executions of inactive requests/connections -> failed. */
    private static function failScheduledOnInactive(): int
    {
        $db = Db::instance();
        $rows = $db->rows(
            "SELECT e.id, e.request_id FROM executions e
             JOIN requests r ON r.id = e.request_id
             JOIN connections c ON c.id = r.connection_id
             WHERE e.mode = 'delayed' AND e.status = 'scheduled'
               AND (r.is_active = 0 OR c.is_active = 0)"
        );
        $n = 0;
        foreach ($rows as $row) {
            $n += $db->execute(
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ? WHERE id = ? AND status = 'scheduled'",
                [Time::now(), 'Request or connection is inactive; the execution will not run. Resubmit when re-enabled.', $row['id']]
            );
            Logger::event('failed', null, (int)$row['request_id'], null, (int)$row['id'], null, null, null,
                'worker skipped a scheduled execution whose request/connection is inactive');
        }
        return $n;
    }

    /** Interrupted worker leftovers: 'running' rows older than the stale threshold. */
    private static function recoverStaleRunning(): int
    {
        $db = Db::instance();
        $minutes = max(1, (int)Config::get('worker.stale_running_minutes', 60));
        $rows = $db->rows(
            "SELECT id, request_id, result_path FROM executions
             WHERE mode = 'delayed' AND status = 'running'
               AND (started_at IS NULL OR started_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))",
            [$minutes]
        );
        $n = 0;
        foreach ($rows as $row) {
            self::deleteResultFile((string)($row['result_path'] ?? ''));
            $n += $db->execute(
                "UPDATE executions
                 SET status = 'failed', finished_at = ?, error_message = ?, result_path = NULL
                 WHERE id = ? AND status = 'running'",
                [Time::now(), 'The worker was interrupted during this query and it was not completed. Submit the request again.', $row['id']]
            );
            Logger::event('failed', null, (int)$row['request_id'], null, (int)$row['id'], null, null, null,
                'worker recovered a stale running execution (interrupted pass)');
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Claim + execute
    // ------------------------------------------------------------------

    private static function claim(int $execId): bool
    {
        $db = Db::instance();
        $claimed = $db->execute(
            "UPDATE executions SET status = 'running', started_at = ?
             WHERE id = ? AND status = 'scheduled'",
            [Time::now(), $execId]
        );
        if ($claimed !== 1) {
            return false;
        }
        Logger::event('started', null, null, null, $execId, null, null, null,
            'claimed by worker pid ' . (string)getmypid());
        return true;
    }

    /**
     * Run one claimed execution end to end. Never throws: every failure path
     * marks the execution failed and logs it.
     */
    private static function executeClaimed(int $execId): bool
    {
        $db = Db::instance();
        $exec = $db->one('SELECT * FROM executions WHERE id = ?', [$execId]);
        if ($exec === null || (string)$exec['status'] !== 'running') {
            return false; // vanished mid-run; nothing sensible to do
        }
        $request = $db->one('SELECT * FROM requests WHERE id = ?', [$exec['request_id']]);
        $connection = $request !== null
            ? $db->one('SELECT * FROM connections WHERE id = ?', [$request['connection_id']])
            : null;
        if ($request === null || $connection === null) {
            self::finalizeFailure($exec, 'Request or connection vanished; execution failed.', false);
            return false;
        }

        $path = Filesystem::resultsDir() . '/' . $execId . '.json';
        $writer = new ResultWriter();
        try {
            $writer->open($path);
        } catch (\RuntimeException $e) {
            self::finalizeFailure($exec, Str::truncate($e->getMessage(), 2000), false);
            return false;
        }

        $result = QueryRunner::run(
            $connection,
            (string)$request['sql_text'],
            (int)$request['timeout_seconds'],
            (int)$request['max_rows'],
            static function (array $row) use ($writer): void {
                $writer->writeRow($row);
            }
        );

        if ($result['ok']) {
            self::finalizeSuccess($exec, $request, $writer, $result);
            return true;
        }

        $writer->discard(); // remove the partial file
        $timedOut = (bool)$result['timed_out'];
        self::markFailed($execId, $timedOut ? 'The query did not finish in time; no result was produced. Timeout exceeded.' : Str::truncate($result['error'], 4000));
        Logger::event(
            $timedOut ? 'timeout' : 'failed',
            null,
            (int)$exec['request_id'],
            null,
            $execId,
            null,
            $result['duration_ms'],
            null,
            Str::truncate($timedOut ? 'query exceeded timeout_seconds' : $result['error'], 2000)
        );
        return false;
    }

    /** @param array<string,mixed> $result shape: QueryRunner::run() (row_count = rows actually streamed) */
    private static function finalizeSuccess(
        array $exec,
        array $request,
        ResultWriter $writer,
        array $result
    ): void {
        $execId = (int)$exec['id'];
        $readyAt = Time::now();
        $ttl = max(1, (int)$request['ttl_minutes']);
        $expiresAt = Time::mysql(Time::addMinutes(new \DateTimeImmutable($readyAt, Time::timezone()), $ttl));
        $absolute = Filesystem::resultsDir() . '/' . $execId . '.json';

        try {
            $writer->finish([
                'mode' => 'delayed',
                'execution_id' => $execId,
                'request_code' => (string)$request['code'],
                'row_count' => $result['row_count'],
                'truncated' => (bool)$result['truncated'],
                'duration_ms' => $result['duration_ms'],
                'executed_at' => Time::iso($readyAt),
            ]);
        } catch (\RuntimeException $e) {
            $writer->discard();
            self::markFailed($execId, Str::truncate($e->getMessage(), 4000));
            Logger::event('failed', null, (int)$exec['request_id'], null, $execId, null, null, null,
                'result file could not be completed: ' . Str::truncate($e->getMessage(), 1800));
            return;
        }

        $token = Str::hex(24); // 48 hex chars; only its SHA-256 is stored
        $size = (int)(@filesize($absolute) ?: 0);
        Db::instance()->execute(
            'UPDATE executions SET status = ?, finished_at = ?, ready_at = ?, expires_at = ?,
                    result_path = ?, result_size_bytes = ?, row_count = ?, truncated = ?,
                    download_token_hash = ?
             WHERE id = ?',
            ['ready', $readyAt, $readyAt, $expiresAt, 'results/' . $execId . '.json',
                $size, (int)$result['row_count'], $result['truncated'] ? 1 : 0, hash('sha256', $token), $execId]
        );

        self::enqueueWebhooks($execId);

        Logger::event('succeeded', null, (int)$exec['request_id'], null, $execId, null,
            (int)$result['duration_ms'], (int)$result['row_count'],
            $result['truncated'] ? 'result ready; truncated at max_rows' : 'result ready');
    }

    private static function finalizeFailure(array $exec, string $message, bool $timedOut): void
    {
        $execId = (int)$exec['id'];
        self::markFailed($execId, $message);
        Logger::event($timedOut ? 'timeout' : 'failed', null, (int)$exec['request_id'], null, $execId,
            null, null, null, Str::truncate($message, 2000));
    }

    private static function markFailed(int $execId, string $message): void
    {
        Db::instance()->execute(
            "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ? WHERE id = ?",
            [Time::now(), $message, $execId]
        );
    }

    /**
     * One webhook delivery row per attached call (state = pending), for the
     * deliverer to send with retries. Existing rows (older delivery for the
     * same call) are never duplicated.
     */
    private static function enqueueWebhooks(int $execId): void
    {
        $db = Db::instance();
        $calls = $db->rows('SELECT id FROM calls WHERE execution_id = ?', [$execId]);
        foreach ($calls as $call) {
            $db->execute(
                'INSERT IGNORE INTO webhook_deliveries (call_id, attempt, state, created_at)
                 VALUES (?, 0, \'pending\', ?)',
                [$call['id'], Time::now()]
            );
        }
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** Per-connection cap of concurrently running delayed executions. */
    private static function capFor(int $connectionId): int
    {
        $row = Db::instance()->one(
            'SELECT max_concurrency FROM connections WHERE id = ?',
            [$connectionId]
        );
        $cap = ($row !== null && (int)$row['max_concurrency'] > 0)
            ? (int)$row['max_concurrency']
            : (int)Config::get('limits.connection_concurrency', 2);
        return max(1, $cap);
    }

    /** @return array<int,int> connection_id => running delayed executions */
    private static function runningCountByConnection(): array
    {
        $rows = Db::instance()->rows(
            "SELECT r.connection_id AS cid, COUNT(*) AS n
             FROM executions e
             JOIN requests r ON r.id = e.request_id
             WHERE e.mode = 'delayed' AND e.status = 'running'
             GROUP BY r.connection_id"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['cid']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Delete a stored result/cache file. Path is validated against the known
     * storage layout before touching the filesystem (defence in depth against
     * a tampered result_path row).
     */
    private static function deleteResultFile(string $relative): bool
    {
        if ($relative === ''
            || preg_match('#^(results|cache)/[A-Za-z0-9._-]+\.json$#i', $relative) !== 1) {
            return false;
        }
        return Cleanup::safeUnlink($relative);
    }
}
