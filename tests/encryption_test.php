<?php
// Encryption smoke test + (--db) a tiny exercise for the metadata Db binder.

require __DIR__ . '/../src/Core/bootstrap.php';

$plain = "C0nn-password! Ünïcode \\ ' quotes \"";
$blob = Services\Encryption::encrypt($plain);
$back = Services\Encryption::decrypt($blob);
echo 'AES-256-GCM roundtrip: ', $back === $plain ? 'PASS' : 'FAIL', PHP_EOL;
echo 'blob head: ', substr($blob, 0, 42), '...', PHP_EOL;

try {
    Services\Encryption::decrypt('gcm:v1:AAAA:BBBB:CCCC');
    echo 'tampered blob: NOT DETECTED (bad)', PHP_EOL;
} catch (\RuntimeException) {
    echo 'tampered blob: detected (good)', PHP_EOL;
}

if (in_array('--db', $argv, true)) {
    try {
        $v = db()->value('SELECT REPLACE(?, ?, ?) AS hello', ['metadata{db}?', '{', '}']);
        echo 'prepared statement echo: ', (string)$v, PHP_EOL;
    } catch (Throwable $t) {
        // expected when metadata DB is offline
        echo 'metadata Db: ', $t->getMessage(), PHP_EOL;
    }
}
