<?php
declare(strict_types=1);
namespace Worker;

use Core\Config;
use Core\Db;
use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Logger;

/**
 * Cleanup pass (cli/cleanup.php, scheduled every few minutes):
 *
 *  1. Ready executions whose expires_at passed -> delete the result/cache
 *     file, mark the execution 'expired', log 'expired'.
 *  2. Failed executions that still reference a file -> delete the leftover,
 *     clear result_path.
 *  3. Instant executions stuck 'running' (web request died mid-query) older
 *     than the request timeout + grace -> mark failed, log 'failed'.
 *  4. Result/cache files whose executions row no longer exists (manual row
 *     deletes, crashed inserts) -> delete the orphan file.
 *  5. Optional: when cleanup.log_retention_days > 0, prune request_logs rows
 *     older than that window (batched). 0 (default) keeps the documented
 *     append-only behavior. Deliveries and the audit log are never deleted.
 */
final class Cleanup
{
    /** @return array{expired:int, files_removed:int, stale_instant:int, logs_removed:int} */
    public static function run(): array
    {
        Filesystem::prepare();
        return [
            'expired' => self::expireReady(),
            'files_removed' => self::clearFailedFiles() + self::sweepOrphanFiles(),
            'stale_instant' => self::failStaleInstant(),
            'logs_removed' => self::pruneLogs(),
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
                "UPDATE executions SET status = 'failed', finished_at = ?, error_message = ?
                 WHERE id = ? AND status = 'running'",
                [Time::now(), 'The instantaneous query was interrupted and never finished. Submit the request again.', $row['id']]
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
     * 5) Optional request_logs retention (cleanup.log_retention_days; 0 = keep
     *     everything). Deletes in batches of 1000 so a large first run cannot
     *     hold one long-running statement.
     */
    private static function pruneLogs(): int
    {
        $days = (int)Config::get('cleanup.log_retention_days', 0);
        if ($days <= 0) {
            return 0;
        }
        $db = Db::instance();
        $cutoff = Time::mysql(Time::addSeconds(Time::nowDt(), -$days * 86400));
        $removed = 0;
        do {
            $n = $db->execute(
                'DELETE FROM request_logs WHERE `timestamp` < ? LIMIT 1000',
                [$cutoff]
            );
            $removed += $n;
        } while ($n > 0);
        return $removed;
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
