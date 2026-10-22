<?php
// Unit test for Core\Http subpath handling: basePath(), url(), path(), and View rewriting.

require __DIR__ . '/../src/Core/bootstrap.php';

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$prop = (new ReflectionClass(\Core\Config::class))->getProperty('data');
$prop->setAccessible(true);
$originalConfig = $prop->getValue();

$setApp = static function (array $app) use ($prop, $originalConfig): void {
    $data = $originalConfig;
    $data['app'] = array_merge($data['app'] ?? [], $app);
    $prop->setValue(null, $data);
};

// 1. basePath() with root domain
$setApp(['base_url' => 'http://gw.internal', 'base_path' => '']);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '';
$check('basePath is empty for http://gw.internal', \Core\Http::basePath() === '', \Core\Http::basePath());

// 2. basePath() with subpath URL
$setApp(['base_url' => 'https://rbb.palood.ir/gateway', 'base_path' => '']);
$check('basePath is /gateway for https://rbb.palood.ir/gateway', \Core\Http::basePath() === '/gateway', \Core\Http::basePath());

// 3. basePath() with trailing slash
$setApp(['base_url' => 'https://rbb.palood.ir/gateway/', 'base_path' => '']);
$check('basePath trims trailing slash', \Core\Http::basePath() === '/gateway', \Core\Http::basePath());

// 4. basePath() with explicit base_path override
$setApp(['base_url' => 'http://gw.internal', 'base_path' => '/custom-gateway']);
$check('base_path config override takes precedence', \Core\Http::basePath() === '/custom-gateway', \Core\Http::basePath());

// 5. basePath() with X-Forwarded-Prefix
$setApp(['base_url' => '', 'base_path' => '']);
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/proxied-gateway';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$check('X-Forwarded-Prefix is detected', \Core\Http::basePath() === '/proxied-gateway', \Core\Http::basePath());
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '';

// 6. basePath() with SCRIPT_NAME Apache Alias
$setApp(['base_url' => '', 'base_path' => '']);
$_SERVER['SCRIPT_NAME'] = '/alias-gateway/index.php';
$check('SCRIPT_NAME Alias is detected', \Core\Http::basePath() === '/alias-gateway', \Core\Http::basePath());
$_SERVER['SCRIPT_NAME'] = '/index.php';

// 7. Http::url() tests
$setApp(['base_url' => 'https://rbb.palood.ir/gateway', 'base_path' => '']);
$check('url(/admin) => /gateway/admin', \Core\Http::url('/admin') === '/gateway/admin', \Core\Http::url('/admin'));
$check('url(/) => /gateway/', \Core\Http::url('/') === '/gateway/', \Core\Http::url('/'));
$check('url(/assets/css/tokens.css) => /gateway/assets/css/tokens.css', \Core\Http::url('/assets/css/tokens.css') === '/gateway/assets/css/tokens.css', \Core\Http::url('/assets/css/tokens.css'));
$check('url(/gateway/admin) is not double-prefixed', \Core\Http::url('/gateway/admin') === '/gateway/admin', \Core\Http::url('/gateway/admin'));
$check('url(https://ext.com) is untouched', \Core\Http::url('https://ext.com') === 'https://ext.com', \Core\Http::url('https://ext.com'));

// 8. Http::path() routing normalization
$_SERVER['REQUEST_URI'] = '/gateway';
$check('path(/gateway) => /', \Core\Http::path() === '/', \Core\Http::path());

$_SERVER['REQUEST_URI'] = '/gateway/';
$check('path(/gateway/) => /', \Core\Http::path() === '/', \Core\Http::path());

$_SERVER['REQUEST_URI'] = '/gateway/admin';
$check('path(/gateway/admin) => /admin', \Core\Http::path() === '/admin', \Core\Http::path());

$_SERVER['REQUEST_URI'] = '/gateway/admin/connections/1?tab=logs';
$check('path(/gateway/admin/connections/1?tab=logs) => /admin/connections/1', \Core\Http::path() === '/admin/connections/1', \Core\Http::path());

$_SERVER['REQUEST_URI'] = '/gateway/api/v1/request/sales';
$check('path(/gateway/api/v1/request/sales) => /api/v1/request/sales', \Core\Http::path() === '/api/v1/request/sales', \Core\Http::path());

$_SERVER['REQUEST_URI'] = '/gateway/health';
$check('path(/gateway/health) => /health', \Core\Http::path() === '/health', \Core\Http::path());

// 9. Root domain backwards-compatibility
$setApp(['base_url' => 'http://gw.internal', 'base_path' => '']);
$_SERVER['REQUEST_URI'] = '/admin/connections';
$check('path on root domain stays /admin/connections', \Core\Http::path() === '/admin/connections', \Core\Http::path());
$check('url(/admin) on root domain stays /admin', \Core\Http::url('/admin') === '/admin', \Core\Http::url('/admin'));

// 10. View::render rewriting with subpath
$setApp(['base_url' => 'https://rbb.palood.ir/gateway', 'base_path' => '']);
ob_start();
@\Admin\View::render('login', ['error' => '', 'username' => ''], 'Login');
$rendered = (string)ob_get_clean();
$check('View::render is not empty', $rendered !== '', 'length: ' . strlen($rendered));
$check('View::render rewrites form action to /gateway/admin/login', str_contains($rendered, 'action="/gateway/admin/login"'));
$check('View::render rewrites css to /gateway/assets/css/tokens.css', str_contains($rendered, 'href="/gateway/assets/css/tokens.css"'));
$check('View::render rewrites js to /gateway/assets/js/admin.js', str_contains($rendered, 'src="/gateway/assets/js/admin.js"'));
$check('View::render includes data-base="/gateway"', str_contains($rendered, 'data-base="/gateway"'));

// Restore config
$prop->setValue(null, $originalConfig);

echo $failures === 0 ? '=== HTTP SUBPATH TEST PASS ===' : "=== HTTP SUBPATH TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
