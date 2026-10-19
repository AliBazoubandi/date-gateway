<?php
// ============================================================================
// Data Gateway - cleanup pass        (run: php cli\cleanup.php)
// Scheduled every 5 minutes via Windows Task Scheduler (see README).
// Deletes expired result/cache files, marks expired executions, and fails
// instant executions left 'running' by an aborted web request.
//
// Exit codes: 0 = pass completed, 1 = the pass itself crashed.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';

$lockFile = Core\Filesystem::storageDir() . '/cleanup.lock';
$fh = @fopen($lockFile, 'c');
if ($fh === false) {
    fwrite(STDERR, "[cleanup] cannot open lock file: {$lockFile}" . PHP_EOL);
    exit(1);
}
if (!@flock($fh, LOCK_EX | LOCK_NB)) {
    echo '[cleanup] another pass is already running; skipping.', PHP_EOL;
    fclose($fh);
    exit(0);
}
$released = static function () use ($fh): void {
    @flock($fh, LOCK_UN);
    @fclose($fh);
};

try {
    if (!db()->lock('gw_cli_cleanup', 0)) {
        echo '[cleanup] metadata DB cleanup lock is busy; skipping.', PHP_EOL;
        $released();
        exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[cleanup] cannot take the metadata DB lock: ' . $e->getMessage() . PHP_EOL);
    $released();
    exit(1);
}

try {
    $s = \Worker\Cleanup::run();
    echo "[cleanup] expired-marked={$s['expired']} leftover-files-removed={$s['files_removed']}",
        " stale-instant-cleaned={$s['stale_instant']}", PHP_EOL,
        "[cleanup] pruned logs={$s['logs_removed']} deliveries={$s['deliveries_removed']}",
        " calls={$s['calls_removed']} executions={$s['executions_removed']}",
        " audit={$s['audit_removed']}", PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[cleanup] FAILED: ' . $e->getMessage() . PHP_EOL);
    try {
        db()->unlock('gw_cli_cleanup');
    } catch (\Throwable) {
    }
    $released();
    exit(1);
}
