<?php
declare(strict_types=1);
namespace Api;

use Core\Db;
use Core\Http;

/**
 * GET /api/v1/requests (or /api/v1/catalog) — self-service discovery endpoint.
 *
 * Allows an authenticated client to introspect:
 *  1. Their client identity (name, type).
 *  2. The gateway's standard endpoint patterns (submit, status, download, health).
 *  3. The catalog of active request codes they have permission to execute,
 *     along with response mode, timing, max row limits, and submission URL.
 */
final class Catalog
{
    public static function handle(): void
    {
        if (!in_array(Http::method(), ['GET', 'HEAD'], true)) {
            Errors::emit(405, 'method_not_allowed', 'Use GET for this endpoint.', ['Allow' => 'GET, HEAD']);
        }

        $client = ApiAuth::requireClient();
        $clientId = (int)$client['id'];
        $db = Db::instance();
        $base = Links::base();

        // Query only requests granted to this client on active connections
        $rows = $db->rows(
            'SELECT r.code, r.name, r.description, r.response_mode,
                    r.instant_cache_seconds, r.delay_minutes, r.ttl_minutes,
                    r.timeout_seconds, r.max_rows
             FROM requests r
             JOIN client_request_permissions p ON p.request_id = r.id
             JOIN connections c ON c.id = r.connection_id
             WHERE p.client_id = ? AND r.is_active = 1 AND c.is_active = 1
             ORDER BY r.code ASC',
            [$clientId]
        );

        $requests = [];
        foreach ($rows as $r) {
            $item = [
                'code' => (string)$r['code'],
                'name' => (string)$r['name'],
                'description' => $r['description'] !== null ? (string)$r['description'] : null,
                'mode' => (string)$r['response_mode'],
                'timeout_seconds' => (int)$r['timeout_seconds'],
                'max_rows' => (int)$r['max_rows'],
                'submit_url' => $base . '/api/v1/request/' . rawurlencode((string)$r['code']),
            ];
            if ($r['response_mode'] === 'instant') {
                $item['cache_seconds'] = (int)$r['instant_cache_seconds'];
            } else {
                $item['delay_minutes'] = (int)$r['delay_minutes'];
                $item['ttl_minutes'] = (int)$r['ttl_minutes'];
            }
            $requests[] = $item;
        }

        json_out([
            'client' => [
                'name' => (string)$client['name'],
                'type' => (string)$client['type'],
            ],
            'endpoints' => [
                'submit' => $base . '/api/v1/request/{code}',
                'status' => $base . '/api/v1/calls/{call_id}',
                'download' => $base . '/api/v1/results/{token}',
                'health' => $base . '/health',
            ],
            'requests' => $requests,
        ]);
    }
}
