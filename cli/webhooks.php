<?php
// ============================================================================
// Data Gateway - webhook deliverer      (run: php cli\webhooks.php)
// Sends pending webhook_deliveries rows (HMAC-signed, retried with backoff).
// The every-minute worker task already drains this queue at the end of each
// pass; this standalone entry point exists for manual runs or for scheduling
// webhook delivery separately from query execution.
//
// Exit codes: 0 = pass completed, 1 = the pass itself crashed.
// Never overlaps itself (file lock) or the worker's delivery phase
// (metadata-DB named lock).
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';

$lockFile = Core\Filesystem::storageDir() . '/webhooks.lock';
$fh = @fopen($lockFile, 'c');
if ($fh === false) {
    fwrite(STDERR, "[webhooks] cannot open lock file: {$lockFile}" . PHP_EOL);
    exit(1);
}
if (!@flock($fh, LOCK_EX | LOCK_NB)) {
    echo '[webhooks] another pass is already running; skipping.', PHP_EOL;
    fclose($fh);
    exit(0);
}
$released = static function () use ($fh): void {
    @flock($fh, LOCK_UN);
    @fclose($fh);
};

try {
    if (!db()->lock('gw_cli_webhooks', 0)) {
        echo '[webhooks] the worker is delivering right now; skipping.', PHP_EOL;
        $released();
        exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[webhooks] cannot take the metadata DB lock: ' . $e->getMessage() . PHP_EOL);
    $released();
    exit(1);
}

try {
    $s = \Services\WebhookDispatcher::deliverDue();
    echo "[webhooks] sent={$s['sent']} failed={$s['failed']} exhausted={$s['exhausted']} skipped={$s['skipped']}",
        PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[webhooks] FAILED: ' . $e->getMessage() . PHP_EOL);
    try {
        db()->unlock('gw_cli_webhooks');
    } catch (\Throwable) {
    }
    $released();
    exit(1);
}
