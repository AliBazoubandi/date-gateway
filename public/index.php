<?php
// ============================================================================
// Data Gateway - web entry point (front controller).
// Routes: /api/v1/* (JSON API), /admin/* (session panel).
// ============================================================================

declare(strict_types=1);

require __DIR__ . '/../src/Core/bootstrap.php';

$path = Core\Http::path();

if ($path === '/') {
    Core\Http::redirect('/admin');
}

if (str_starts_with($path, '/api/')) {
    Api\Router::dispatch($path);
    exit;
}

if (str_starts_with($path, '/admin')) {
    Admin\Router::dispatch($path);
    exit;
}

http_response_code(404);
echo '<!doctype html><meta charset="utf-8"><title>404</title>';
echo '<h1>404 - not found</h1><p><a href="/admin">Admin</a></p>';
