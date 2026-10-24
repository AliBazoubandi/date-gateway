<?php
declare(strict_types=1);
namespace Worker;

use Core\Config;
use Core\Db;
use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\ExecutionError;
use Services\Logger;
use Services\QueryRunner;
use Services\ResultArchive;
use Services\ResultTooLargeException;
use Services\StorageGuard;

/**
 * Delayed-execution worker: moves executions scheduled -> running -> ready|failed.
 *
 * Scheduler model (P2): a delayed execution starts running as soon as the
 * worker sees it — scheduled_at is the earliest DOWNLOAD time the client was
 * promised (delay_minutes), not the earliest run time. Priority is
 * earliest-deadline-first (ORDER BY scheduled_at): when a connection is at
 * its concurrency cap, the run whose promise opens soonest claims the next
 * free slot, so results are stored before their promised time whenever
 * capacity allows. ready_at never precedes scheduled_at: a run that finishes
 * early holds its result (status/download refuse it until the promise opens)
 * and expires_at measures the TTL from the moment downloads actually open.
 *
 * Concurrency model:
 *  - A pass is sequential inside itself. Parallelism comes from running one
 *    pass per target connection (cli/worker_pool.php), so a 30-minute query on
 *    connection A no longer holds connection B's due executions waiting (P1-1).
 *  - Each pass holds a file lock plus a metadata-DB GET_LOCK. Global mode uses
 *    `gw_cli_worker` / worker.lock; connection mode uses
 *    `gw_cli_worker:<id>` / worker-<id>.lock, which is what lets different
 *    connections run at the same time while two workers never share one.
 *  - Claims are atomic: UPDATE ... WHERE status = 'scheduled' (InnoDB row lock).
 *  - The per-connection cap (connections.max_concurrency, else config default 5)
 *    limits concurrently RUNNING executions of BOTH modes; it is checked DB-side
 *    so it stays valid when several hosts share one metadata DB. A
 *    single-connection pass still runs its own rows one after another: the cap
 *    bounds overlap across hosts and passes, it does not intra-parallelise one
 *    connection.
 *  - worker.pass_budget_seconds bounds one pass so a long queue cannot outlive
 *    the schedule interval; the remainder stays scheduled for the next minute.
 *
 * Crash policy:
 *  - 'running' rows older than config worker.stale_running_minutes are leftovers
 *    from an interrupted worker and are failed (partial file deleted).
 *  - Scheduled executions of deactivated requests/connections are failed (they
 *    would otherwise stay stuck and keep winning cache rule 1 forever).
 *  - No automatic retries: a failed execution stays failed; clients resubmit.
 *
 * Success flow (per execution): stream rows to storage/results/{id}.json, mark
 * ready with ready_at = max(completion, scheduled_at) and expires_at =
 * ready_at + request ttl_minutes, set result_path/size/row_count/truncated
 * (download credentials are per call), and copy the finished file to the
 * archive (ResultArchive::copyResult) so the evidence exists whatever the
 * client does next. Webhook deliveries for the attached calls are enqueued by
 * the worker as 'pending' rows on success AND on failure, to be sent by the
 * webhook deliverer; the payload follows the execution status and the
 * deliverer holds 'ready' payloads whose download window has not opened yet.
 */
final class Worker
{
    /**
     * One worker pass.
     *
     * Global mode (`$connectionId` = null) is the historical single-worker
     * behaviour: housekeeping plus every due execution, in one process.
     * Connection mode (used by cli/worker_pool.php) restricts the pass to one
     * target connection and leaves housekeeping to the caller, so a slow query
     * on connection A can never delay a due execution on connection B (P1-1).
     *
     * `$budgetSeconds` bounds the pass wall time: the child stops claiming
     * once it is spent, and what it did not reach stays 'scheduled' for the
     * next minute. Without it a 50-execution pass can run for hours while the
     * schedule keeps firing.
     *
     * @return array{due:int,ready:int,failed:int,blocked:int,deferred:int,cleaned_stale:int,cleaned_inactive:int}
     */
    public static function run(?int $connectionId = null, ?int $budgetSeconds = null): array
    {
        Filesystem::prepare();
        $stats = [
            'due' => 0, 'ready' => 0, 'failed' => 0, 'blocked' => 0, 'deferred' => 0,
            'cleaned_stale' => 0, 'cleaned_inactive' => 0,
        ];

        // Housekeeping first, so neither kind can later win a claim. In pool
        // mode the parent already swept (once per pass, not once per child).
        if ($connectionId === null) {
            $swept = self::housekeeping();
            $stats['cleaned_inactive'] = $swept['cleaned_inactive'];
            $stats['cleaned_stale'] = $swept['cleaned_stale'];
        }

        $db = Db::instance();
        // Every scheduled execution is runnable NOW: scheduled_at is the
        // earliest-download promise, not a start time. Earliest deadline
        // first, so a connection at its cap frees its next slot to the run
        // whose promise opens soonest and results land before promise time
        // whenever capacity allows.
        $dueSql =
            "SELECT e.id, r.connection_id
             FROM executions e
             JOIN requests r ON r.id = e.request_id
             JOIN connections c ON c.id = r.connection_id
             WHERE e.mode = 'delayed' AND e.status = 'scheduled'
               AND r.is_active = 1 AND c.is_active = 1";
        $dueArgs = [];
        if ($connectionId !== null) {
            $dueSql .= ' AND r.connection_id = ?';
            $dueArgs[] = $connectionId;
        }
        $due = $db->rows($dueSql . '
             ORDER BY e.scheduled_at ASC, e.id ASC
             LIMIT 200', $dueArgs);
        $stats['due'] = count($due);
        if ($due === []) {
            return $stats;
        }

        $maxPerRun = max(1, (int)Config::get('worker.max_per_run', 50));
        $deadline = $budgetSeconds !== null && $budgetSeconds > 0
            ? microtime(true) + $budgetSeconds
            : null;
        $running = self::runningCountByConnection($connectionId);
        $caps = [];
        $processed = 0;

        foreach ($due as $row) {
            if ($processed >= $maxPerRun) {
                // Cap the pass; everything left stays due for the next run.
                $stats['deferred'] = max(0, count($due) - $stats['ready'] - $stats['failed'] - $stats['blocked']);
                break;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                // Wall-clock budget spent: hand the rest to the next pass
                // instead of running for hours past the schedule interval.
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

    /**
     * The two global sweeps. Connection-mode children never run these (they
     * would repeat the same full-table work once per child), so the pool runs
     * them exactly once per pass.
     *
     * @return array{cleaned_stale:int,cleaned_inactive:int}
     */
    public static function housekeeping(): array
    {
        return [
            'cleaned_inactive' => self::failScheduledOnInactive(),
            'cleaned_stale' => self::recoverStaleRunning(),
        ];
    }

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
            $n += self::markFailed(
                (int)$row['id'],
                'Request or connection is inactive; the execution will not run. Resubmit when re-enabled.',
                ExecutionError::FAILED,
                'scheduled'
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
            // The interrupted pass may also have left the published file
            // (crashed right after rename) or the in-flight temp partial.
            Cleanup::safeUnlink("results/{$row['id']}.json");
            Cleanup::safeUnlink("results/{$row['id']}.json.tmp");
            $n += self::markFailed(
                (int)$row['id'],
                'The worker was interrupted during this query and it was not completed. Submit the request again.',
                ExecutionError::FAILED,
                'running'
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

        // Refuse before writing anything: on a nearly full volume the stream
        // would die mid-query and leave a crashed execution behind.
        $noRoom = StorageGuard::reason();
        if ($noRoom !== '') {
            self::finalizeFailure($exec, 'storage_low: ' . $noRoom, false, ExecutionError::STORAGE_LOW);
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

        $params = [];
        if (!empty($exec['params_json'])) {
            $decoded = json_decode((string)$exec['params_json'], true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        try {
            $result = QueryRunner::run(
                $connection,
                (string)$request['sql_text'],
                (int)$request['timeout_seconds'],
                (int)$request['max_rows'],
                static function (array $row) use ($writer): void {
                    $writer->writeRow($row);
                },
                $params
            );
        } catch (ResultTooLargeException $e) {
            $writer->discard(); // remove the partial file
            $message = Str::truncate($e->getMessage(), 4000);
            self::markFailed($execId, $message, ExecutionError::TOO_LARGE);
            Logger::event('failed', null, (int)$exec['request_id'], null, $execId, null, null, null, $message);
            return false;
        }

        if ($result['ok']) {
            self::finalizeSuccess($exec, $request, $writer, $result);
            return true;
        }

        $writer->discard(); // remove the partial file
        $timedOut = (bool)$result['timed_out'];
        $code = $result['rejected'] ? ExecutionError::REJECTED
            : ($timedOut ? ExecutionError::TIMEOUT : ExecutionError::FAILED);
        self::markFailed($execId, $timedOut
            ? 'The query did not finish in time; no result was produced. Timeout exceeded.'
            : Str::truncate($result['error'], 4000), $code);
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
        $completedAt = Time::now();
        // The download window opens at the later of "the query finished" and
        // "the delay promise the client was given": a fast query holds its
        // result until scheduled_at (status/download refuse it before then),
        // and expires_at measures the TTL from the moment downloads open.
        $scheduledAt = (string)($exec['scheduled_at'] ?? '');
        $readyAt = ($scheduledAt !== '' && $scheduledAt > $completedAt) ? $scheduledAt : $completedAt;
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
                'executed_at' => Time::iso($completedAt),
            ]);
        } catch (\RuntimeException $e) {
            $writer->discard();
            self::markFailed($execId, Str::truncate($e->getMessage(), 4000));
            Logger::event('failed', null, (int)$exec['request_id'], null, $execId, null, null, null,
                'result file could not be completed: ' . Str::truncate($e->getMessage(), 1800));
            return;
        }

        $size = (int)(@filesize($absolute) ?: 0);
        try {
            Db::instance()->transaction(static function () use ($execId, $readyAt, $expiresAt, $size, $result): void {
                Db::instance()->execute(
                    'UPDATE executions SET status = ?, finished_at = ?, ready_at = ?, expires_at = ?,
                            result_path = ?, result_size_bytes = ?, row_count = ?, truncated = ?
                     WHERE id = ?',
                    ['ready', $readyAt, $readyAt, $expiresAt, 'results/' . $execId . '.json',
                        $size, (int)$result['row_count'], $result['truncated'] ? 1 : 0, $execId]
                );
                // Same transaction as the status flip: a ready row nobody was
                // told about is indistinguishable from a lost run, and the
                // calls waiting on it never time out.
                self::enqueueWebhooks($execId);
            });
        } catch (\Throwable $e) {
            // Nothing was recorded, so the published file has no row pointing
            // at it: remove it and fail the execution instead of leaving a
            // result no client can ever download. The failure is stored without
            // a webhook row - the delivery insert is the statement that just
            // failed, and repeating it would abort the pass for every other
            // due execution. The status endpoint still reports the truth.
            Cleanup::safeUnlink('results/' . $execId . '.json');
            $message = 'result could not be recorded: ' . Str::truncate($e->getMessage(), 1800);
            self::markFailed($execId, $message, ExecutionError::FAILED, null, false);
            Logger::event('failed', null, (int)$exec['request_id'], null, $execId, null, null, null,
                $message . ' (no webhook queued; poll the status endpoint)');
            return;
        }

        Logger::event('succeeded', null, (int)$exec['request_id'], null, $execId, null,
            (int)$result['duration_ms'], (int)$result['row_count'],
            $result['truncated'] ? 'result ready; truncated at max_rows' : 'result ready');

        // Keep the result no matter what the client does next: archive a copy
        // NOW, while the run is fresh. The live file stays at its downloadable
        // path; the expiry sweep later moves it and recognises this copy by
        // its deterministic name. A failed copy must not fail the run — the
        // live result still serves, and the sweep archives it at expiry.
        $archived = ResultArchive::copyResult(
            'results/' . $execId . '.json',
            $execId,
            (string)$request['code'],
            self::originatingClientId($execId),
            $expiresAt
        );
        if ($archived === null) {
            error_log("ResultArchive: ready-time copy failed for execution {$execId}; "
                . 'the expiry sweep will archive the live file instead');
        }
    }

    /** The client whose call first triggered the execution (archive naming). */
    private static function originatingClientId(int $execId): ?int
    {
        $v = Db::instance()->value(
            'SELECT client_id FROM calls WHERE execution_id = ? ORDER BY created_at ASC, id ASC LIMIT 1',
            [$execId]
        );
        return $v !== null ? (int)$v : null;
    }

    private static function finalizeFailure(
        array $exec,
        string $message,
        bool $timedOut,
        ?string $code = null
    ): void {
        self::markFailed((int)$exec['id'], $message,
            $code ?? ($timedOut ? ExecutionError::TIMEOUT : ExecutionError::FAILED));
        Logger::event($timedOut ? 'timeout' : 'failed', null, (int)$exec['request_id'], null, (int)$exec['id'],
            null, null, null, Str::truncate($message, 2000));
    }

    /**
     * Record a failed execution. $notify = false is for the one caller that is
     * failing *because* the delivery insert was refused: queueing again would
     * throw a second time and take the whole pass down with it, while the
     * status endpoint still tells the client the truth.
     */
    private static function markFailed(
        int $execId,
        string $message,
        string $code = ExecutionError::FAILED,
        ?string $expectStatus = null,
        bool $notify = true
    ): int {
        $sql = "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?, error_code = ?,
                    result_path = NULL, result_size_bytes = NULL
             WHERE id = ?";
        $args = [Time::now(), $message, $code, $execId];
        if ($expectStatus !== null) {
            $sql .= ' AND status = ?';
            $args[] = $expectStatus;
        }
        $flipped = Db::instance()->execute($sql, $args);
        if ($flipped > 0 && $notify) {
            self::enqueueWebhooks($execId);
        }
        return $flipped;
    }

    /**
     * One webhook delivery row per attached call (state = pending), for the
     * deliverer to send with retries. Existing rows (older delivery for the
     * same call) are never duplicated. Called for ready AND failed runs: the
     * payload the deliverer builds follows the execution status, so a client
     * that is waiting on this call hears back either way.
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

    /** Per-connection cap of concurrently running executions (either mode). */
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

    /**
     * Executions currently running per target connection, BOTH modes.
     *
     * The cap protects the target database, which cannot tell which gateway
     * mode issued the query, so a shared counter is the only one that means
     * anything: counting modes separately let one connection run 2x the cap.
     *
     * @return array<int,int> connection_id => running executions
     */
    private static function runningCountByConnection(?int $connectionId = null): array
    {
        $sql = "SELECT r.connection_id AS cid, COUNT(*) AS n
             FROM executions e
             JOIN requests r ON r.id = e.request_id
             WHERE e.status = 'running'";
        $args = [];
        if ($connectionId !== null) {
            $sql .= ' AND r.connection_id = ?';
            $args[] = $connectionId;
        }
        $rows = Db::instance()->rows($sql . ' GROUP BY r.connection_id', $args);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['cid']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Active connections that can own delayed work, in id order. The pool
     * spawns one child per id (P1-1).
     *
     * @return list<int>
     */
    public static function activeConnectionIds(): array
    {
        $rows = Db::instance()->rows('SELECT id FROM connections WHERE is_active = 1 ORDER BY id');
        return array_map(static fn (array $r): int => (int)$r['id'], $rows);
    }

    /** Per-connection concurrency cap, for the pool's bounded fan-out. */
    public static function capForConnection(int $connectionId): int
    {
        return self::capFor($connectionId);
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
