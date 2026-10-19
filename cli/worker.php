<?php
// ============================================================================
// Data Gateway - delayed execution worker
//   (run: php cli\worker.php                    - everything, one pass)
//   (run: php cli\worker.php --connection=ID     - one target connection only)
//
// Scheduled every minute via Windows Task Scheduler; exact schtasks command
// is in the README. In production the schedule runs cli\worker_pool.php, which
// spawns one --connection=ID child per active connection so a slow query on one
// connection cannot stall another (P1-1). Plain `worker.php` stays the manual
// single-pass mode and keeps its historical behaviour (no budget, no filter).
//
// Exit codes: 0 = pass completed (nothing to do is also 0),
//             1 = the pass itself crashed (see STDERR).
// Another instance holding the lock is NORMAL (0, quiet) for schedulers.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';

// A pass may stream hundreds of thousands of rows; no CLI time limit.
set_time_limit(0);
ini_set('max_execution_time', '0');

$connectionId = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--connection=(\d+)$/i', $a, $m)) {
        $connectionId = (int)$m[1];
    }
}
$poolMode = $connectionId !== null;
$tag = $poolMode ? "[worker:{$connectionId}]" : '[worker]';

// --- fence 1: file lock (same machine) ---------------------------------------
// Per-connection name in pool mode: that is what lets two connections run at
// the same time while two workers never share one connection.
$lockFile = Core\Filesystem::storageDir() . ($poolMode ? "/worker-{$connectionId}.lock" : '/worker.lock');
$fh = @fopen($lockFile, 'c');
if ($fh === false) {
    fwrite(STDERR, "{$tag} cannot open lock file: {$lockFile}" . PHP_EOL);
    exit(1);
}
if (!@flock($fh, LOCK_EX | LOCK_NB)) {
    // The 1-minute schedule overlapping a long pass is expected, not an error.
    echo $tag, ' another pass is already running; skipping.', PHP_EOL;
    fclose($fh);
    exit(0);
}

// --- fence 2: metadata DB named lock (also across separate hosts) ------------
$lockName = $poolMode ? "gw_cli_worker:{$connectionId}" : 'gw_cli_worker';
$released = static function () use ($fh): void {
    @flock($fh, LOCK_UN);
    @fclose($fh);
};
try {
    if (!db()->lock($lockName, 0)) {
        echo $tag, ' metadata DB worker lock is busy; skipping.', PHP_EOL;
        $released();
        exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $tag . ' cannot take the metadata DB lock: ' . $e->getMessage() . PHP_EOL);
    $released();
    exit(1);
}

try {
    // Pool children bound their wall time so one pass cannot outlive the
    // schedule; the manual global pass keeps processing until the queue is dry.
    $budget = $poolMode ? max(1, (int)Core\Config::get('worker.pass_budget_seconds', 50)) : null;
    $s = \Worker\Worker::run($connectionId, $budget);
    echo $tag, " due={$s['due']} ready={$s['ready']} failed={$s['failed']}",
        " blocked={$s['blocked']} deferred={$s['deferred']}",
        " stale-cleaned={$s['cleaned_stale']} inactive-cleaned={$s['cleaned_inactive']}", PHP_EOL;
    if (!$poolMode) {
        // The pool drains webhooks once in its parent; a child must not race it.
        deliverWebhooks();
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, $tag . ' FAILED: ' . $e->getMessage() . PHP_EOL);
    try {
        db()->unlock($lockName);
    } catch (\Throwable) {
        // connection already gone; the named lock frees itself with it
    }
    $released();
    exit(1);
}

/**
 * Drain the webhook queue after the query work, under the shared
 * 'gw_cli_webhooks' fence (mutual exclusion with cli/webhooks.php). A delivery
 * problem must NOT fail the pass: the executions already succeeded.
 */
function deliverWebhooks(): void
{
    try {
        if (!db()->lock('gw_cli_webhooks', 0)) {
            echo '[worker] webhook delivery is busy elsewhere; skipping.', PHP_EOL;
            return;
        }
        $s = \Services\WebhookDispatcher::deliverDue();
        echo '[worker] webhooks sent=', $s['sent'], ' failed=', $s['failed'],
            " exhausted={$s['exhausted']} skipped={$s['skipped']}", PHP_EOL;
        db()->unlock('gw_cli_webhooks');
    } catch (\Throwable $e) {
        fwrite(STDERR, '[worker] webhook delivery failed (will retry next pass): '
            . $e->getMessage() . PHP_EOL);
        try {
            db()->unlock('gw_cli_webhooks');
        } catch (\Throwable) {
        }
    }
}
