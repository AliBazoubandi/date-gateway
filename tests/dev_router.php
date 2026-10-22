<?php
// router file for `php -S` (dev only): the built-in server 404s dotted path
// segments, e.g. /api/v1/request/smk.delayed. Apache production rewrites
// everything through public/.htaccess, so this file is NOT used in prod.
//
// Usage:  php -S 127.0.0.1:PORT -t public tests/dev_router.php

if (PHP_SAPI === 'cli-server') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . '/../public' . $path)) {
        return false; // static asset: let the built-in server stream it
    }
    if (str_starts_with($path, '/gateway/')) {
        $sub = substr($path, strlen('/gateway'));
        if ($sub !== '/' && is_file(__DIR__ . '/../public' . $sub)) {
            $ext = pathinfo($sub, PATHINFO_EXTENSION);
            $mime = match ($ext) {
                'css' => 'text/css; charset=utf-8',
                'js' => 'application/javascript; charset=utf-8',
                'svg' => 'image/svg+xml',
                'woff2' => 'font/woff2',
                'png' => 'image/png',
                default => 'application/octet-stream',
            };
            header('Content-Type: ' . $mime);
            readfile(__DIR__ . '/../public' . $sub);
            exit;
        }
    }
    require __DIR__ . '/../public/index.php';
}
