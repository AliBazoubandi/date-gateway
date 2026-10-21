<?php
// ============================================================================
// Data Gateway - one-off migration helper
// (run: php cli\encrypt_webhook_secrets.php)
//
// Encryption-at-rest upgrade for clients.webhook_secret_enc: every value that
// is still legacy plaintext (written before the webhook-secret hardening) is
// re-stored as an AES-256-GCM blob under security.master_key.
//
// Run it right after applying sql\migrations\2026-10-20-secret-hardening.sql
// so no plaintext secret survives at rest. Re-runnable: rows already holding
// a "gcm:v1:..." blob are skipped. The dispatcher also upgrades values lazily
// on the next delivery, but this closes the window in one step instead of
// waiting for traffic.
//
// Exit code 0 = nothing left unencrypted, 1 = something failed.
// ============================================================================

declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

use Core\Db;
use Services\Encryption;

$db = Db::instance();
$rows = $db->rows(
    "SELECT id, webhook_secret_enc FROM clients
      WHERE webhook_secret_enc IS NOT NULL AND webhook_secret_enc <> ''"
);

$done = 0;
$already = 0;
$failed = 0;
foreach ($rows as $row) {
    $stored = (string)$row['webhook_secret_enc'];
    if (str_starts_with($stored, 'gcm:')) {
        $already++;
        continue;
    }
    try {
        $db->execute(
            'UPDATE clients SET webhook_secret_enc = ? WHERE id = ? AND webhook_secret_enc = ?',
            [Encryption::encrypt($stored), (int)$row['id'], $stored]
        );
        $done++;
        echo 'encrypted client #', (int)$row['id'], PHP_EOL;
    } catch (\Throwable $e) {
        $failed++;
        echo 'FAILED client #', (int)$row['id'], ': ', $e->getMessage(), PHP_EOL;
    }
}

$left = (int)$db->value(
    "SELECT COUNT(*) FROM clients
      WHERE webhook_secret_enc IS NOT NULL AND webhook_secret_enc <> ''
        AND webhook_secret_enc NOT LIKE 'gcm:%'"
);
echo "done: {$done} encrypted, {$already} already encrypted, {$failed} failed, "
    . "{$left} plaintext left", PHP_EOL;
exit($failed === 0 && $left === 0 ? 0 : 1);
