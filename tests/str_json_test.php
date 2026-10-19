<?php
// Unit test for Core\Str::json() and the json_out() response layer: an
// unencodable value must produce a string, never false (a `: string` caller
// with false gets a TypeError -> 500) and never an empty body.

require __DIR__ . '/../src/Core/bootstrap.php';

use Core\Str;

// json_out() exits, so the response-layer cases run in a child process that
// re-invokes this file with a mode (same pattern as driver_tls_test).
if (isset($argv[1])) {
    if ($argv[1] === 'out-ok') {
        json_out(['ok' => true, 'n' => 2]);
    }
    if ($argv[1] === 'out-inf') {
        json_out(['v' => INF]);
    }
    fwrite(STDERR, 'unknown child mode: ' . $argv[1] . PHP_EOL);
    exit(3);
}

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

// Runs one child mode and returns its stdout.
$out = static function (string $mode): string {
    $proc = proc_open([PHP_BINARY, __FILE__, $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        return '';
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if (trim($stderr) !== '') {
        echo '     child stderr: ' . trim($stderr) . PHP_EOL;
    }
    return $stdout;
};

// ---- values that json_encode() refuses outright ------------------------------
$cycle = new stdClass();
$cycle->self = $cycle;
$resource = fopen('php://memory', 'r');

foreach ([
    'INF' => ['v' => INF],
    'NAN' => ['v' => NAN],
    '-INF' => ['v' => -INF],
    'resource' => ['v' => $resource],
    'recursive object' => $cycle,
] as $label => $value) {
    $r = Str::json($value);
    $check("Str::json({$label}) returns a string", is_string($r), gettype($r));
    $check("Str::json({$label}) falls back to an empty object", $r === '{}', $r);
}
fclose($resource);

// rawjson_encode() of the same values is false - that is the bug being fixed.
$check('baseline: json_encode(INF) really is false',
    json_encode(['v' => INF], JSON_INVALID_UTF8_SUBSTITUTE) === false);

// ---- malformed UTF-8: substituted, not failed -------------------------------
$bad = Str::json(['v' => "\xC3\x28(truncated"] + ['n' => "\xFF\xFE"]);
$check('invalid UTF-8 encodes to a string', is_string($bad), gettype($bad));
$check('invalid UTF-8 becomes the replacement char',
    str_contains($bad, '\\uFFFD') || str_contains($bad, "\xEF\xBF\xBD"), $bad);
$check('invalid UTF-8 does not hit the fallback', $bad !== '{}');

// ---- normal values keep encoding exactly as before --------------------------
$check('array encodes unescaped',
    Str::json(['a' => 1, 'b' => 'پرسش', 'c' => 'https://x/y'])
        === '{"a":1,"b":"پرسش","c":"https://x/y"}',
    Str::json(['a' => 1, 'b' => 'پرسش', 'c' => 'https://x/y']));
$check('null/false/empty stay valid JSON',
    Str::json(null) === 'null' && Str::json(false) === 'false' && Str::json([]) === '[]');
$check('extra flags are honored',
    str_contains(Str::json(['a' => 1], JSON_PRETTY_PRINT), "\n"),
    str_replace("\n", '\\n', Str::json(['a' => 1], JSON_PRETTY_PRINT)));

// ---- the response layer -----------------------------------------------------
$okOut = $out('out-ok');
$infOut = $out('out-inf');
$check('json_out() of a normal payload is unchanged',
    $okOut === '{"ok":true,"n":2}', $okOut);
$check('json_out() of an unencodable payload is not an empty body',
    trim($infOut) === '{}', var_export($infOut, true));

echo $failures === 0 ? '=== STR JSON TEST PASS ===' : "=== STR JSON TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
