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
        $counts = [
            'requests' => (int)$db->value('SELECT COUNT(*) FROM requests'),
            'requests_active' => (int)$db->value('SELECT COUNT(*) FROM requests WHERE is_active = 1'),
            'connections' => (int)$db->value('SELECT COUNT(*) FROM connections'),
            'clients' => (int)$db->value('SELECT COUNT(*) FROM clients WHERE is_active = 1'),
            'activity' => [
                'scheduled' => (int)$db->value("SELECT COUNT(*) FROM executions WHERE status = 'scheduled'"),
                'running' => (int)$db->value("SELECT COUNT(*) FROM executions WHERE status = 'running'"),
                'ready' => (int)$db->value("SELECT COUNT(*) FROM executions WHERE status = 'ready'"),
                'failed_24h' => (int)$db->value(
                    "SELECT COUNT(*) FROM executions
                     WHERE status = 'failed' AND finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                ),
                'calls_24h' => (int)$db->value('SELECT COUNT(*) FROM calls WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
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
