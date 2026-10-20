<?php
declare(strict_types=1);
namespace Services;

use Core\Config;
use Core\Db;
use Core\Time;

/**
 * The worker heartbeat (worker_state, one row) that GET /health reads.
 *
 * Written by whoever runs a pass over EVERY connection - cli/worker_pool.php,
 * or a manual global `php cli\worker.php` - and never by a --connection child,
 * whose work covers a single connection. A pass that had failures still records:
 * the timestamp answers "does the schedule still fire?", and the failure count
 * beside it answers "was what it found healthy?".
 *
 * Two timestamps, because one is not enough: a legitimate 30-minute query would
 * otherwise make /health report 503 for 30 minutes (the finished timestamp is
 * stale while the pass is genuinely working). markStarted() writes the start and
 * leaves the previous finish alone; record() closes the same row at the end.
 * The effective age is the NEWER of the two (passAgeSeconds()).
 */
final class WorkerState
{
    /**
     * Marks a pass as started, before it does any work. On a fresh row the
     * finish is set to the same instant: last_pass_finished_at is NOT NULL, and
     * "started but not finished yet" is exactly what the pair then says.
     */
    public static function markStarted(string $startedAt): void
    {
        Db::instance()->execute(
            'INSERT INTO worker_state
                (id, last_pass_started_at, last_pass_finished_at, last_pass_failures)
             VALUES (1, ?, ?, 0)
             ON DUPLICATE KEY UPDATE last_pass_started_at = VALUES(last_pass_started_at)',
            [$startedAt, $startedAt]
        );
    }

    public static function record(string $startedAt, int $failures): void
    {
        Db::instance()->execute(
            'INSERT INTO worker_state
                (id, last_pass_started_at, last_pass_finished_at, last_pass_failures)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_pass_started_at  = VALUES(last_pass_started_at),
                last_pass_finished_at = VALUES(last_pass_finished_at),
                last_pass_failures    = VALUES(last_pass_failures)',
            [$startedAt, Time::now(), max(0, $failures)]
        );
    }

    /**
     * The recorded pass, or null when there is none yet: a fresh installation,
     * or an upgrade whose worker_state table has not been created.
     *
     * @return array{last_pass_started_at:string,last_pass_finished_at:string,last_pass_failures:int}|null
     */
    public static function lastPass(): ?array
    {
        try {
            $row = Db::instance()->one('SELECT * FROM worker_state WHERE id = 1');
        } catch (\Throwable) {
            // A health check must answer, not 500: no readable heartbeat is the
            // same answer as no heartbeat (reported as stale, with the reason).
            return null;
        }
        if ($row === null) {
            return null;
        }
        return [
            'last_pass_started_at' => (string)$row['last_pass_started_at'],
            'last_pass_finished_at' => (string)$row['last_pass_finished_at'],
            'last_pass_failures' => (int)$row['last_pass_failures'],
        ];
    }

    /**
     * Seconds since the given pass finished, or null when there is nothing to
     * measure (never recorded, or a value this version cannot read).
     */
    public static function ageSeconds(?string $finishedAt): ?int
    {
        if ($finishedAt === null || $finishedAt === '') {
            return null;
        }
        $dt = Time::fromSql($finishedAt);
        if ($dt === null) {
            return null;
        }
        return max(0, Time::nowDt()->getTimestamp() - $dt->getTimestamp());
    }

    /**
     * The age of the newest evidence that the schedule is alive: the newer of
     * the pass's start and its finish. A pass that is still running only has
     * its start, which is exactly the case this answers correctly — a long
     * query is work in progress, not a dead scheduler. Once a pool really
     * stops (crashed or task disabled) no new start appears either, so the
     * answer still turns stale after max_silence_seconds.
     */
    public static function passAgeSeconds(?array $pass): ?int
    {
        if ($pass === null) {
            return null;
        }
        $started = self::ageSeconds($pass['last_pass_started_at'] ?? null);
        $finished = self::ageSeconds($pass['last_pass_finished_at'] ?? null);
        if ($started === null) {
            return $finished;
        }
        if ($finished === null) {
            return $started;
        }
        return min($started, $finished);
    }

    /** The configured silence GET /health tolerates before it calls the worker stale. */
    public static function maxSilenceSeconds(): int
    {
        return max(0, (int)Config::get('worker.max_silence_seconds', 300));
    }
}
