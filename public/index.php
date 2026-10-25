<?php
// ============================================================================
// Data Gateway - web entry point (front controller).
// Routes: /api/v1/* (JSON API), /admin/* (session panel), /health (probe).
// ============================================================================

declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

$path = Core\Http::path();

if ($path === '/') {
    Core\Http::redirect('/admin');
}

if ($path === '/health') {
    Api\Health::handle();
    exit;
}

if (str_starts_with($path, '/api/')) {
    Api\Router::dispatch($path);
    exit;
}

if (str_starts_with($path, '/admin')) {
    Admin\Router::dispatch($path);
    exit;
}

if (str_starts_with($path, '/assets/')) {
    $rel = substr($path, strlen('/assets/'));
    $assetsDir = realpath(__DIR__ . '/assets');
    $filePath = realpath(__DIR__ . '/assets/' . $rel);
    if ($assetsDir !== false && $filePath !== false && str_starts_with($filePath, $assetsDir) && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'application/javascript; charset=UTF-8',
            'svg'   => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'png'   => 'image/png',
            'ico'   => 'image/x-icon',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
        exit;
    }
}

http_response_code(404);
echo '<!doctype html><meta charset="utf-8"><title>404</title>';
echo '<h1>404 - not found</h1><p><a href="' . Core\Str::e(Core\Http::url('/admin')) . '">Admin</a></p>';
