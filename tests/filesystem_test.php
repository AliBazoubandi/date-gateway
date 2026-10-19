<?php
// Unit test for Core\Filesystem::resultPath validation (P1-7).

require __DIR__ . '/../src/Core/bootstrap.php';
require_once __DIR__ . '/../src/Core/Filesystem.php';

\Core\Filesystem::prepare();

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};
$throws = static function (string $relative): bool {
    try {
        \Core\Filesystem::resultPath($relative);
        return false;
    } catch (\RuntimeException) {
        return true;
    }
};

$base = str_replace('\\', '/', realpath(\Core\Filesystem::storageDir()));

$ok = \Core\Filesystem::resultPath('results/123.json');
$check('valid results path resolves', str_starts_with(str_replace('\\', '/', $ok), $base . '/results/'), $ok);

// cache/ is the legacy prefix: prepare() no longer creates it, but a stored
// path in it must still resolve so an older installation stays downloadable.
$legacyCache = \Core\Filesystem::storageDir() . '/cache';
if (!is_dir($legacyCache) && !mkdir($legacyCache, 0770, true)) {
    $check('legacy cache fixture dir created', false);
}
$ok = \Core\Filesystem::resultPath('cache/abc.JSON');
$check('legacy cache path + uppercase ext accepted', str_starts_with(str_replace('\\', '/', $ok), $base . '/cache/'), $ok);

$check('dot-dot traversal throws', $throws('../config/.env'));
$check('nested dot-dot throws', $throws('results/../secrets.json'));
$check('absolute path throws', $throws('/etc/passwd'));
$check('windows drive path throws', $throws('C:/xampp/htdocs/config/.env'));
$check('wrong extension throws', $throws('results/payload.txt'));
$check('empty name throws', $throws('results/.json'));
$check('empty string throws', $throws(''));
$check('missing subdir throws', $throws('nope/123.json'));

echo $failures === 0 ? '=== FILESYSTEM TEST PASS ===' : "=== FILESYSTEM TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
