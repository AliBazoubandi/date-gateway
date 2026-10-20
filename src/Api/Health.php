<?php
declare(strict_types=1);
namespace Api;

use Core\Db;
use Core\Filesystem;
use Core\Http;
use Core\Time;
use Services\StorageGuard;
use Services\WorkerState;

/**
 * GET /health - is this gateway able to do its job right now?
 *
 * 200 while every check passes, 503 as soon as one does not, with the individual
 * checks in the body so a monitoring system can tell WHICH one failed.
 *
 * The endpoint is deliberately unauthenticated (a load balancer and an uptime
 * probe hold no client key), so the answer contains only what a health check
 * needs: booleans, byte counts and timestamps. No database name, no paths, no
 * connection rows, no driver error text - the most an outside probe can learn is
 * whether the service is up.
 */
final class Health
{
    public static function handle(): void
    {
        if (Http::method() !== 'GET') {
            header('Allow: GET');
            Errors::emit(405, 'method_not_allowed', 'The health check answers GET.');
            return;
        }

        $database = self::database();
        $db = $database === '';
        $writable = self::storageWritable();

        // The same floor the worker applies before opening a result file, so the
        // health answer and the behaviour cannot drift apart.
        $space = StorageGuard::hasRoom();

        // Only read the heartbeat when the DB answered: with the metadata server
        // down, Db::instance() would try to connect again and only delay the 503.
        $pass = $db ? WorkerState::lastPass() : null;
        // A pass that is still running counts as recent through its START: a
        // legitimate 30-minute query must not make the gateway look dead.
        $age = WorkerState::passAgeSeconds($pass);
        $recent = $age !== null && $age <= WorkerState::maxSilenceSeconds();

        $free = Filesystem::freeBytes();
        $ok = $db && $writable && $space && $recent;

        json_out([
            'status' => $ok ? 'ok' : 'degraded',
            'time' => Time::iso(Time::nowDt()),
            'checks' => [
                'database' => $db,
                'database_error' => $database === '' ? null : $database,
                'storage_writable' => $writable,
                // null = this host cannot report free space, which the space
                // check treats as unknown rather than full.
                'storage_free_bytes' => $free >= 0 ? $free : null,
                'storage_space' => $space,
                'worker_last_pass_at' => Time::iso($pass['last_pass_finished_at'] ?? null),
                // Additive: the pass start, so a monitor can tell a long pass
                // from a heartbeat that only moved because a pass began.
                'worker_last_pass_started_at' => Time::iso($pass['last_pass_started_at'] ?? null),
                'worker_last_pass_age_seconds' => $age,
                'worker_last_pass_failures' => $pass['last_pass_failures'] ?? null,
                'worker_recent' => $recent,
            ],
        ], $ok ? 200 : 503);
    }

    /**
     * '' when the metadata DB answers, otherwise a short reason. mysqli's own
     * message is not echoed: it names the host, the user and the database.
     */
    private static function database(): string
    {
        try {
            return Db::instance()->ping() ? '' : 'the metadata database did not answer';
        } catch (\Throwable) {
            return 'the metadata database is unreachable';
        }
    }

    /**
     * A write probe, not is_writable(): the check exists to catch an ACL or a
     * read-only mount that PHP's stat-based answer still calls writable, and a
     * gateway that cannot open a file in storage cannot serve a result.
     */
    private static function storageWritable(): bool
    {
        try {
            // Create the directories first, exactly as the worker and cleanup do
            // before writing: a fresh installation has no storage\results yet, and
            // that is not an outage - it is the state before the first result.
            Filesystem::prepare();
        } catch (\Throwable) {
            return false;
        }
        // Inside results/, next to the result files themselves. The cleanup
        // sweep only looks at numeric <execution id>.json[.tmp] names, so a
        // probe is never mistaken for a result. The name is unique per request
        // (pid + random): with a fixed `health.tmp`, two concurrent probes
        // unlink each other's file and one of them reports a false outage.
        $probe = Filesystem::resultsDir() . '/health.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($probe, 'x') === false) {
            return false;
        }
        // A file this process cannot remove is a volume it does not own.
        return @unlink($probe);
    }
}
