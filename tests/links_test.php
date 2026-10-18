<?php
// Unit test for Api\Links::base() validation + fail-fast behavior.

require __DIR__ . '/../src/Core/bootstrap.php';

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

// Swap the loaded config per case (test-only; Config has no public setter).
$prop = (new ReflectionClass(\Core\Config::class))->getProperty('data');
$prop->setAccessible(true);
$original = $prop->getValue();

$with = static function (array $app) use ($prop, $original): void {
    $data = $original;
    $data['app'] = array_merge($data['app'] ?? [], $app);
    $prop->setValue(null, $data);
};
$baseThrows = static function (): string {
    try {
        \Api\Links::base();
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
};

$with(['base_url' => 'https://gw.internal/', 'debug' => false]);
$check('https base_url, trailing slash trimmed', \Api\Links::base() === 'https://gw.internal', \Api\Links::base());

$with(['base_url' => 'http://gw.internal', 'debug' => false]);
$check('http base_url in production throws', $baseThrows() !== '');

$with(['base_url' => 'http://gw.internal:8080', 'debug' => true]);
$check('http base_url allowed in debug', \Api\Links::base() === 'http://gw.internal:8080', \Api\Links::base());

$with(['base_url' => 'gw.internal', 'debug' => true]);
$check('non-URL base_url throws', $baseThrows() !== '');

$with(['base_url' => 'ftp://gw.internal', 'debug' => true]);
$check('non-http(s) scheme throws', $baseThrows() !== '');

$with(['base_url' => '', 'debug' => true]);
$check('empty base_url in CLI throws', $baseThrows() !== '');

$prop->setValue(null, $original);

echo $failures === 0 ? '=== LINKS TEST PASS ===' : "=== LINKS TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
