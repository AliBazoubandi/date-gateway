<?php
declare(strict_types=1);
namespace Api;

use Core\Str;

/**
 * /api/v1 routes:
 *   GET  /api/v1/requests           Catalog (discovery: permitted requests + endpoints)
 *   GET  /api/v1/catalog            Catalog (alias)
 *   POST /api/v1/request/{code}     Submit
 *   GET  /api/v1/calls/{call_id}    Status
 *   GET  /api/v1/results/{token}    Download
 *
 * GET /health is not one of them: a monitor holds no client key and this
 * dispatcher only ever sees /api/v1/*, so public/index.php answers /health
 * before routing (see Api\Health).
 */
final class Router
{
    public static function dispatch(string $path): void
    {
        $parts = explode('/', trim($path, '/')); // [api, v1, ...]
        if (($parts[1] ?? '') !== 'v1') {
            Errors::notFound('Unknown API endpoint.');
        }

        // Discovery routes: /api/v1/requests and /api/v1/catalog
        if (count($parts) === 3) {
            $kind = $parts[2];
            if ($kind === 'requests' || $kind === 'catalog') {
                Catalog::handle();
                return;
            }
            Errors::notFound('Unknown API endpoint.');
        }

        if (count($parts) !== 4) {
            Errors::notFound('Unknown API endpoint.');
        }
        $kind = $parts[2];
        $value = rawurldecode($parts[3] ?? '');

        if ($kind === 'request' && $value !== '') {
            if (!Str::isCode($value)) {
                // Same answer as an unknown code: no enumeration aid.
                Errors::notFound('Unknown request code.');
            }
            Submit::handle($value);
            return;
        }
        if ($kind === 'calls' && Str::isCallId($value)) {
            Status::handle($value);
            return;
        }
        if ($kind === 'results' && Str::isToken($value)) {
            Download::handle($value);
            return;
        }
        Errors::notFound('Unknown API endpoint.');
    }
}
