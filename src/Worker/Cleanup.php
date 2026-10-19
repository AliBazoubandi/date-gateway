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

/**
 * Cleanup pass (cli/cleanup.php, scheduled every few minutes):
 *
 *  1. Ready executions whose expires_at passed -> delete the result/cache
 *     file, mark the execution 'expired', log 'expired'.
 *  2. Failed or expired executions that still reference a file -> delete the
 *     leftover, clear result_path.
 *  3. Instant executions stuck 'running' (web request died mid-query) older
 *     than the request timeout + grace -> mark failed, log 'failed'.
 *  4. Result/cache files whose executions row no longer exists (manual row
 *     deletes, crashed inserts) -> delete the orphan file.
 *  5. Retention (cleanup.retention.*): prune request_logs, webhook deliveries,
 *     calls, executions and the admin audit log, each only when its own window
 *     is > 0 (0 = keep forever, the default). Deletion is batched and ordered
 *     so foreign keys hold: deliveries before the calls that own them, result
 *     files before the executions that name them, and never a call with a
 *     pending delivery or an execution another row still points at.
 */
final class Cleanup
{
    /** Rows deleted per statement, so a huge first prune cannot lock the table. */
    private const PRUNE_BATCH = 1000;

    /** Batches per table per pass: bounds one cleanup run at 20k rows a table. */
    private const PRUNE_BATCHES_PER_PASS = 20;

    /**
     * @return array{expired:int, files_removed:int, stale_instant:int,
     *     logs_removed:int, deliveries_removed:int, calls_removed:int,
     *     executions_removed:int, audit_removed:int}
     */
    public static function run(): array
    {
        Filesystem::prepare();
        $expired = self::expireReady();
        $files = self::clearFailedFiles() + self::clearExpiredFiles() + self::sweepOrphanFiles();
        $stale = self::failStaleInstant();
        $pruned = [
            // Deliveries first, then the calls that own them, then the
            // executions those calls pointed at (FK order).
            'webhook_deliveries' => self::pruneDeliveries(),
            'calls' => self::pruneCalls(),
            'executions' => self::pruneExecutions(),
            'request_logs' => self::pruneLogs(),
            'admin_audit_log' => self::pruneAudit(),
        ];
        return [
            'expired' => $expired,
            'files_removed' => $files,
            'stale_instant' => $stale,
            'logs_removed' => $pruned['request_logs'],
            'deliveries_removed' => $pruned['webhook_deliveries'],
            'calls_removed' => $pruned['calls'],
            'executions_removed' => $pruned['executions'],
            'audit_removed' => $pruned['admin_audit_log'],
        ];
    }

    // ------------------------------------------------------------------

    /** 1) Expired ready executions: remove file, mark expired, clear the path. */
    private static function expireReady(): int
    {
        $db = Db::instance();
        $rows = $db->rows(
            "SELECT id, request_id, result_path, row_count FROM executions
             WHERE status = 'ready' AND expires_at IS NOT NULL AND expires_at <= NOW()"
        );
        $n = 0;
        foreach ($rows as $row) {
            // Delete the file FIRST: while status is still 'ready' a racing
            // download sees the missing file and self-heals to 410 (existing
            // logic), instead of streaming a file that vanishes mid-read.
            $removed = self::safeUnlink((string)$row['result_path']);
            $n += $db->execute(
                "UPDATE executions SET status = 'expired', result_path = NULL, result_size_bytes = NULL
                 WHERE id = ? AND status = 'ready'",
                [$row['id']]
            );
            Logger::event('expired', null, (int)$row['request_id'], null, (int)$row['id'], null,
                null, $row['row_count'] !== null ? (int)$row['row_count'] : null,
                $removed ? 'ttl reached: result file deleted' : 'ttl reached: no file to delete');
        }
        return $n;
    }

    /**
     * 2) Executions that still reference a result file but can never serve it
     *    (failed, or the inconsistent scheduled/running-with-a-path leftover):
     *    delete the file AND its in-flight .tmp sibling, clear the path.
     *    A row only ever gets result_path once ready, so a matched
     *    scheduled/running row is already inconsistent — and an actively
     *    writing execution has result_path IS NULL, so it can never match.
     */
    private static function clearFailedFiles(): int
    {
        $db = Db::instance();
        $rows = $db->rows(
            "SELECT id, result_path FROM executions
             WHERE status IN ('scheduled', 'running', 'failed') AND result_path IS NOT NULL"
        );
        $n = 0;
        foreach ($rows as $row) {
            if (self::safeUnlink((string)$row['result_path'])) {
                $n++;
            }
            if (self::safeUnlink("results/{$row['id']}.json.tmp")) {
                $n++;
            }
            $db->execute(
                'UPDATE executions SET result_path = NULL, result_size_bytes = NULL WHERE id = ?',
                [$row['id']]
            );
        }
        return $n;
    }

    /**
     * 2b) Executions already marked 'expired' (e.g. by a racing download that
     *     hit the TTL before this pass ran) that still reference a file:
     *     delete the leftover and clear the path.
     */
    private static function clearExpiredFiles(): int
    {
        $db = Db::instance();
        $rows = $db->rows(
            "SELECT id, result_path FROM executions
             WHERE status = 'expired' AND result_path IS NOT NULL"
        );
        $n = 0;
        foreach ($rows as $row) {
            if (self::safeUnlink((string)$row['result_path'])) {
                $n++;
            }
            $db->execute(
                'UPDATE executions SET result_path = NULL, result_size_bytes = NULL WHERE id = ?',
                [$row['id']]
            );
        }
        return $n;
    }

    /** 3) Instant executions frozen in 'running' (aborted web process). */
    private static function failStaleInstant(): int
    {
        $db = Db::instance();
        $rows = $db->rows(
            "SELECT e.id, e.request_id, e.started_at, r.timeout_seconds FROM executions e
             JOIN requests r ON r.id = e.request_id
             WHERE e.mode = 'instant' AND e.status = 'running' AND e.started_at IS NOT NULL"
        );
        $grace = 120; // seconds of slack beyond the request timeout
        $n = 0;
        foreach ($rows as $row) {
            $started = Time::fromSql((string)$row['started_at']);
            $limit = Time::addSeconds(Time::nowDt(), -((int)$row['timeout_seconds'] + $grace));
            if ($started === null || $started > $limit) {
                continue; // still within a plausible running window
            }
            $n += $db->execute(
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?, error_code = ?
                 WHERE id = ? AND status = 'running'",
                [Time::now(), 'The instantaneous query was interrupted and never finished. Submit the request again.',
                    ExecutionError::FAILED, $row['id']]
            );
            self::safeUnlink("results/{$row['id']}.json");
            self::safeUnlink("results/{$row['id']}.json.tmp");
            Logger::event('failed', null, (int)$row['request_id'], null, (int)$row['id'], null, null, null,
                'cleanup recovered a stale instant execution (interrupted web process)');
        }
        return $n;
    }

    /** 4) Files whose executions row no longer exists -> delete the orphan. */
    private static function sweepOrphanFiles(): int
    {
        $db = Db::instance();
        $n = 0;
        foreach (['results', 'cache'] as $sub) {
            $files = glob(Filesystem::absoluteFromRelative($sub . '/*.json')) ?: [];
            $tmps = glob(Filesystem::absoluteFromRelative($sub . '/*.json.tmp')) ?: [];
            foreach (array_merge($files, $tmps) as $absolute) {
                // Only the documented per-execution layout, ids are numeric.
                if (preg_match('#/([0-9]+)\.json(\.tmp)?$#', $absolute, $m) !== 1) {
                    continue;
                }
                $count = (int)$db->value('SELECT COUNT(*) FROM executions WHERE id = ?', [(int)$m[1]]);
                if ($count !== 0) {
                    continue; // still referenced
                }
                $relative = $sub . '/' . $m[1] . ($m[2] ?? '');
                if (self::safeUnlink($relative)) {
                    $n++;
                    Logger::event('expired', null, null, null, (int)$m[1], null, null, null,
                        "orphaned {$relative} removed (no executions row)");
                }
            }
        }
        return $n;
    }

    /**
     * 5a) Webhook deliveries older than their window, settled ones only: a
     *     pending delivery is work the deliverer still owes the client, and it
     *     keeps its call alive (calls are RESTRICT-referenced by deliveries).
     */
    private static function pruneDeliveries(): int
    {
        $cutoff = self::cutoff('webhook_deliveries_days');
        if ($cutoff === null) {
            return 0;
        }
        $db = Db::instance();
        return self::pruneBatched(
            static fn (): array => $db->rows(
                "SELECT id FROM webhook_deliveries
                 WHERE state <> 'pending' AND created_at < ? ORDER BY id LIMIT " . self::PRUNE_BATCH,
                [$cutoff]
            ),
            static fn (array $rows): int => self::deleteByIds(
                $db,
                'webhook_deliveries',
                $rows
            )
        );
    }

    /**
     * 5b) Calls older than their window. A call whose delivery is still pending
     *     is never deleted; the settled delivery rows of the calls that do go
     *     are deleted first, in the same transaction (fk_whd_call is RESTRICT).
     */
    private static function pruneCalls(): int
    {
        $cutoff = self::cutoff('calls_days');
        if ($cutoff === null) {
            return 0;
        }
        $db = Db::instance();
        return self::pruneBatched(
            static fn (): array => $db->rows(
                "SELECT c.id FROM calls c
                 WHERE c.created_at < ?
                   AND NOT EXISTS (SELECT 1 FROM webhook_deliveries d
                                   WHERE d.call_id = c.id AND d.state = 'pending')
                 ORDER BY c.id LIMIT " . self::PRUNE_BATCH,
                [$cutoff]
            ),
            static function (array $rows) use ($db): int {
                $ids = self::keyList($rows);
                $marks = self::marks(count($ids));
                $db->begin();
                try {
                    $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN ({$marks})", $ids);
                    $n = $db->execute("DELETE FROM calls WHERE id IN ({$marks})", $ids);
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                return $n;
            }
        );
    }

    /**
     * 5c) Executions older than their window, terminal ones only, and never one
     *     a call still points at (calls.execution_id would silently become
     *     NULL and the client would lose its result). The result file and any
     *     .tmp leftover are removed BEFORE the row, so a crash in between
     *     leaves an expired-looking row rather than an unowned file.
     */
    private static function pruneExecutions(): int
    {
        $cutoff = self::cutoff('executions_days');
        if ($cutoff === null) {
            return 0;
        }
        $db = Db::instance();
        return self::pruneBatched(
            static fn (): array => $db->rows(
                "SELECT e.id, e.result_path FROM executions e
                 WHERE e.status NOT IN ('scheduled', 'running')
                   AND COALESCE(e.finished_at, e.ready_at, e.scheduled_at) < ?
                   AND NOT EXISTS (SELECT 1 FROM calls c WHERE c.execution_id = e.id)
                 ORDER BY e.id LIMIT " . self::PRUNE_BATCH,
                [$cutoff]
            ),
            static function (array $rows) use ($db): int {
                foreach ($rows as $row) {
                    self::safeUnlink((string)($row['result_path'] ?? ''));
                    self::safeUnlink('results/' . (int)$row['id'] . '.json.tmp');
                }
                return $db->execute(
                    'DELETE FROM executions WHERE id IN (' . self::marks(count($rows)) . ')',
                    self::keyList($rows)
                );
            }
        );
    }

    /**
     * 5d) request_logs retention. cleanup.retention.request_logs_days is the
     *     current knob; the older cleanup.log_retention_days still applies when
     *     it is 0, so an existing deployment does not stop pruning on upgrade.
     */
    private static function pruneLogs(): int
    {
        $days = self::retentionDays('request_logs_days');
        if ($days <= 0) {
            $days = max(0, (int)Config::get('cleanup.log_retention_days', 0));
        }
        if ($days <= 0) {
            return 0;
        }
        $cutoff = self::cutoffAt($days);
        $db = Db::instance();
        return self::pruneBatched(
            static fn (): array => $db->rows(
                'SELECT id FROM request_logs WHERE `timestamp` < ? ORDER BY id LIMIT ' . self::PRUNE_BATCH,
                [$cutoff]
            ),
            static fn (array $rows): int => self::deleteByIds($db, 'request_logs', $rows)
        );
    }

    /** 5e) Admin audit log retention (append-only history stays by default). */
    private static function pruneAudit(): int
    {
        $days = self::retentionDays('audit_days');
        if ($days <= 0) {
            return 0;
        }
        $cutoff = self::cutoffAt($days);
        $db = Db::instance();
        return self::pruneBatched(
            static fn (): array => $db->rows(
                'SELECT id FROM admin_audit_log WHERE `timestamp` < ? ORDER BY id LIMIT ' . self::PRUNE_BATCH,
                [$cutoff]
            ),
            static fn (array $rows): int => self::deleteByIds($db, 'admin_audit_log', $rows)
        );
    }

    private static function retentionDays(string $key): int
    {
        return max(0, (int)Config::get('cleanup.retention.' . $key, 0));
    }

    /** Cutoff datetime for a config window; null when the window is off. */
    private static function cutoff(string $key): ?string
    {
        $days = self::retentionDays($key);
        return $days > 0 ? self::cutoffAt($days) : null;
    }

    private static function cutoffAt(int $days): string
    {
        return Time::mysql(Time::addSeconds(Time::nowDt(), -$days * 86400));
    }

    /**
     * One retention loop: select keeps handing out deletable rows until the
     * window is empty or the pass budget is spent, so the very first prune
     * after turning a window on cannot run past the scheduled task's limit.
     *
     * @param callable():list<array<string,mixed>> $select
     * @param callable(list<array<string,mixed>>):int $delete
     */
    private static function pruneBatched(callable $select, callable $delete): int
    {
        $removed = 0;
        for ($pass = 0; $pass < self::PRUNE_BATCHES_PER_PASS; $pass++) {
            $rows = $select();
            if ($rows === []) {
                break;
            }
            $removed += $delete($rows);
        }
        return $removed;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function deleteByIds(Db $db, string $table, array $rows): int
    {
        $ids = self::keyList($rows);
        return $db->execute(
            "DELETE FROM `{$table}` WHERE id IN (" . self::marks(count($ids)) . ')',
            $ids
        );
    }

    /**
     * Primary keys of the selected rows, kept as they are: calls are keyed by
     * 32-hex strings, the other tables by BIGINT. Casting them (or interpolating
     * them) would compare a hex call id as a number and match the wrong rows.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string|int>
     */
    private static function keyList(array $rows): array
    {
        return array_values(array_map(static fn (array $r) => $r['id'], $rows));
    }

    private static function marks(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }

    /**
     * Unlink a file stored under storage/. The path MUST match the known
     * layout (results/name.json or cache/name.json, optionally the in-flight
     * .tmp sibling) and must resolve INSIDE the storage directory; anything
     * else is refused without touching disk.
     */
    public static function safeUnlink(string $relative): bool
    {
        if ($relative === ''
            || preg_match('#^(results|cache)/[A-Za-z0-9._-]+\.json(\.tmp)?$#i', $relative) !== 1) {
            return false;
        }
        $absolute = Filesystem::absoluteFromRelative($relative);
        $target = realpath($absolute);
        $base = realpath(Filesystem::storageDir());
        if ($target === false || $base === false) {
            return false; // missing file or storage dir: nothing to delete
        }
        $prefix = str_replace('\\', '/', $base) . '/';
        $resolved = str_replace('\\', '/', $target);
        if (!str_starts_with($resolved, $prefix)) {
            return false; // refuses to delete anything outside storage/
        }
        return @unlink($resolved);
    }
}
