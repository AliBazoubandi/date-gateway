<?php
// Unit test for the bootstrap config-failure path in both SAPIs.
//
// bootstrap.php runs before anything else, and its error branch wrote to
// STDERR unconditionally. STDERR is defined only under the CLI SAPI, so a bad
// master key under Apache/FPM died with "Undefined constant STDERR" instead of
// printing the reason - the one message an operator needs. The fix branches on
// PHP_SAPI: the CLI still gets the text, a web request logs it and gets the
// generic 500 body.

require __DIR__ . '/../src/Core/bootstrap.php';

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

// A throwaway docroot whose index.php does nothing but bootstrap the gateway.
// An invalid GATEWAY_MASTER_KEY then aborts startup in both modes below
// (Env::load lets the real environment win over config/.env).
$docroot = sys_get_temp_dir() . '/gw_sapi_' . bin2hex(random_bytes(4));
if (!mkdir($docroot)) {
    fwrite(STDERR, 'cannot create ' . $docroot . PHP_EOL);
    exit(2);
}
$index = $docroot . '/index.php';
file_put_contents($index, "<?php\nrequire '"
    . str_replace('\\', '/', BASE_PATH) . "/src/Core/bootstrap.php';\necho 'BOOTED';\n");
register_shutdown_function(static function () use ($docroot, $index): void {
    if (is_file($index)) {
        unlink($index);
    }
    @rmdir($docroot);
});

$badEnv = array_merge(getenv(), ['GATEWAY_MASTER_KEY' => 'deadbeef']);
$goodEnv = getenv();
unset($goodEnv['GATEWAY_MASTER_KEY']);

// ---- CLI: the reason must still be printed ----------------------------------
$cliOut = '';
$cliErr = '';
$proc = proc_open([PHP_BINARY, $index], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $badEnv);
$check('CLI child started', is_resource($proc));
if (is_resource($proc)) {
    $cliOut = (string)stream_get_contents($pipes[1]);
    $cliErr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $check('CLI: prints the reason on STDERR',
        str_contains($cliErr, 'Data Gateway cannot start') && str_contains($cliErr, 'master_key'),
        trim($cliErr));
    $check('CLI: no fatal about the constant itself', !str_contains($cliErr, 'STDERR'), trim($cliErr));
    $check('CLI: exit code 1', $exit === 1, (string)$exit);
    $check('CLI: body is the generic message', trim($cliOut) === 'Configuration error.', trim($cliOut));
}

// ---- web SAPI: the same failure must not become "Undefined constant" --------
// Array command form (no cmd.exe wrapper) so proc_terminate really kills it;
// random port so a sibling server can never answer instead of us.
$port = rand(20000, 60000);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $badEnv);
$check('php -S child started', is_resource($server));
$body = '';
$code = 0;
$serverLog = '';
if (is_resource($server)) {
    register_shutdown_function(static function () use ($server): void {
        // is_resource(): the normal path already terminated and closed it,
        // and closing twice is a fatal at shutdown.
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    });
    usleep(700000);
    $ch = curl_init('http://127.0.0.1:' . $port . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // The server keeps both streams open, so reading them only makes sense
    // after it is terminated (that is what sends EOF).
    proc_terminate($server);
    $serverLog = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($server);

    $check('web: 500, not a blank or 200 response', $code === 500, (string)$code);
    $check('web: body is the generic message', str_contains($body, 'Configuration error.'), trim($body));
    // The regression itself: writing to the CLI-only constant inside a web
    // request. Fatal text can surface either in the body (display_errors) or
    // in the server log, so both are checked.
    $check('web: body carries no "Undefined constant" fatal',
        !str_contains($body, 'Undefined constant'), trim($body));
    $check('web: server log carries no "Undefined constant" fatal',
        !str_contains($serverLog, 'Undefined constant'), trim($serverLog));
    $check('web: the reason reached the error log',
        str_contains($serverLog, 'Data Gateway cannot start'), trim($serverLog));
}

// ---- control: a valid key boots in the same harness -------------------------
// Without this, a broken docroot/server could pass every assertion above by
// producing nothing at all.
$ctrlPort = rand(20000, 60000);
$ctrl = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $ctrlPort, '-t', $docroot],
    [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes, BASE_PATH, $goodEnv);
if (is_resource($ctrl)) {
    register_shutdown_function(static function () use ($ctrl): void {
        if (is_resource($ctrl)) {
            proc_terminate($ctrl);
            proc_close($ctrl);
        }
    });
    usleep(700000);
    $ch = curl_init('http://127.0.0.1:' . $ctrlPort . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $ctrlBody = (string)curl_exec($ch);
    $ctrlCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    proc_terminate($ctrl);
    proc_close($ctrl);
    $check('control: a valid config boots and answers',
        $ctrlCode === 200 && trim($ctrlBody) === 'BOOTED', $ctrlCode . ' ' . trim($ctrlBody));
} else {
    $check('control: php -S child started', false);
}

echo $failures === 0 ? '=== SAPI BOOTSTRAP TEST PASS ===' : "=== SAPI BOOTSTRAP TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
