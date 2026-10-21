<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\View;
use Core\Db;

final class DashboardController
{
    public static function get(): void
    {
        $db = Db::instance();
        // One round trip instead of nine: scalar subselects in a single row.
        // The statement is constant (no user input), so no placeholders are
        // needed; COUNT(*) subselects cost the same as the separate queries
        // but share the one connection hit.
        $c = (array)$db->one(
            "SELECT
                (SELECT COUNT(*) FROM requests) AS requests,
                (SELECT COUNT(*) FROM requests WHERE is_active = 1) AS requests_active,
                (SELECT COUNT(*) FROM connections) AS connections,
                (SELECT COUNT(*) FROM clients WHERE is_active = 1) AS clients,
                (SELECT COUNT(*) FROM executions WHERE status = 'scheduled') AS scheduled,
                (SELECT COUNT(*) FROM executions WHERE status = 'running') AS running,
                (SELECT COUNT(*) FROM executions WHERE status = 'ready') AS ready,
                (SELECT COUNT(*) FROM executions
                  WHERE status = 'failed' AND finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_24h,
                (SELECT COUNT(*) FROM calls
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS calls_24h"
        );
        $counts = [
            'requests' => (int)($c['requests'] ?? 0),
            'requests_active' => (int)($c['requests_active'] ?? 0),
            'connections' => (int)($c['connections'] ?? 0),
            'clients' => (int)($c['clients'] ?? 0),
            'activity' => [
                'scheduled' => (int)($c['scheduled'] ?? 0),
                'running' => (int)($c['running'] ?? 0),
                'ready' => (int)($c['ready'] ?? 0),
                'failed_24h' => (int)($c['failed_24h'] ?? 0),
                'calls_24h' => (int)($c['calls_24h'] ?? 0),
            ],
        ];
        $recent = $db->rows(
            'SELECT `timestamp`, event, request_id, client_id, call_id, message
             FROM request_logs
             ORDER BY id DESC
             LIMIT 10'
        );
        View::render('dashboard', ['counts' => $counts, 'recent' => $recent], 'Dashboard');
    }
}
