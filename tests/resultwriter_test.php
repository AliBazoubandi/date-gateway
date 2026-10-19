<?php
// Unit test for Worker\ResultWriter: tmp+rename publish, byte cap, discard.

require __DIR__ . '/../src/Core/bootstrap.php';
require_once __DIR__ . '/../src/Core/Filesystem.php';
require_once __DIR__ . '/../src/Worker/ResultWriter.php';

\Core\Filesystem::prepare();

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$id = 'rwt_' . \Core\Str::hex(4);
$final = \Core\Filesystem::resultsDir() . '/' . $id . '.json';
$tmp = $final . '.tmp';

// --- happy path: finish() publishes by rename, tmp is gone -----------------
$w = new \Worker\ResultWriter();
$w->open($final);
$w->writeRow(['a' => 1]);
$w->writeRow(['b' => 'x']);
$w->finish(['row_count' => 2]);
$check('finish publishes final file', is_file($final) && !is_file($tmp));
$decoded = json_decode((string)file_get_contents($final), true);
$check('published JSON is the documented envelope',
    is_array($decoded) && $decoded['data'] === [['a' => 1], ['b' => 'x']]
    && ($decoded['meta']['row_count'] ?? null) === 2);

// --- byte cap: ResultTooLargeException, discard removes the tmp ------------
$w = new \Worker\ResultWriter(64);
$w->open($final);
$threw = false;
try {
    $w->writeRow(['pad' => str_repeat('x', 200)]);
} catch (\Services\ResultTooLargeException $e) {
    $threw = true;
}
$check('write past the cap throws ResultTooLargeException', $threw);
$w->discard();
$check('discard removes the tmp partial', !is_file($tmp));

// --- open() cleans a stale tmp from a previous crashed run -----------------
file_put_contents($tmp, '{"data":[1,2');
$w = new \Worker\ResultWriter();
$w->open($final);
$check('open removes a stale tmp first', !is_file($tmp) || file_get_contents($tmp) === '{"data":[');
$w->discard();

if (is_file($final)) {
    @unlink($final);
}
@unlink($tmp);

echo $failures === 0 ? '=== RESULTWRITER TEST PASS ===' : "=== RESULTWRITER TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
