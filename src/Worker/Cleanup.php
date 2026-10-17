<?php
declare(strict_types=1);
namespace Worker;

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
 *
 *  Deliveries, logs and the audit log are never deleted (append-only).
 */
final class Cleanup
{
    /** @return array{expired:int, files_removed:int, stale_instant:int} */
    public static function run(): array
    {
        Filesystem::prepare();
        return [
            'expired' => self::expireReady(),
            'files_removed' => self::clearFailedFiles(),
            'stale_instant' => self::failStaleInstant(),
        ];
    }

    // ------------------------------------------------------------------

    /** 1) Expired ready executions: remove file, mark expired, log. */
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
                "UPDATE executions SET status = 'expired' WHERE id = ? AND status = 'ready'",
                [$row['id']]
            );
            Logger::event('expired', null, (int)$row['request_id'], null, (int)$row['id'], null,
                null, $row['row_count'] !== null ? (int)$row['row_count'] : null,
                $removed ? 'ttl reached: result file deleted' : 'ttl reached: no file to delete');
        }
        return $n;
    }

    /** 2) Failed executions that reference a file: clear the dangling path. */
    private static function clearFailedFiles(): int
    {
        $db = Db::instance();
        $rows = $db->rows(
            "SELECT id, result_path FROM executions
             WHERE status = 'failed' AND result_path IS NOT NULL"
        );
        $n = 0;
        foreach ($rows as $row) {
            self::safeUnlink((string)$row['result_path']);
            $db->execute(
                'UPDATE executions SET result_path = NULL, result_size_bytes = NULL WHERE id = ?',
                [$row['id']]
            );
            $n++;
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
            Logger::event('failed', null, (int)$row['request_id'], null, (int)$row['id'], null, null, null,
                'cleanup recovered a stale instant execution (interrupted web process)');
        }
        return $n;
    }

    /**
     * Unlink a file stored under storage/. The path MUST match the known
     * layout (results/name.json or cache/name.json) and must resolve INSIDE
     * the storage directory; anything else is refused without touching disk.
     */
    public static function safeUnlink(string $relative): bool
    {
        if ($relative === ''
            || preg_match('#^(results|cache)/[A-Za-z0-9._-]+\.json$#i', $relative) !== 1) {
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
