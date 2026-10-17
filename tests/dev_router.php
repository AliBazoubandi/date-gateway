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
    require __DIR__ . '/../public/index.php';
}
