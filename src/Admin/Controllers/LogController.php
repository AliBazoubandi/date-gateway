<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\View;
use Core\Db;
use Core\Http;

/**
 * Read-only viewer for request_logs (append-only). Filters: client, request,
 * event, response mode (of the request), date range. 100 rows per page,
 * newest first.
 */
final class LogController
{
    private const PER_PAGE = 100;

    /** The full event vocabulary (schema.sql comment; Logger::event writers). */
    private const EVENTS = [
        'submitted', 'denied', 'shared', 'started', 'succeeded', 'failed',
        'downloaded', 'expired', 'webhook_sent', 'webhook_failed',
        'instant_executed', 'instant_cache_hit', 'rate_limited', 'timeout',
    ];

    public static function index(): void
    {
        $db = Db::instance();

        $f = self::filters();
        [$where, $params] = self::where($f);

        $total = (int)$db->value("SELECT COUNT(*) FROM request_logs l $where", $params);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min(max(1, $f['page']), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $db->rows(
            "SELECT l.id, l.`timestamp`, l.event, l.ip, l.duration_ms, l.row_count, l.message,
                    l.call_id, l.execution_id,
                    r.code AS request_code, COALESCE(e.mode, r.response_mode) AS mode,
                    c.name AS client_name
             FROM request_logs l
             LEFT JOIN requests r ON r.id = l.request_id
             LEFT JOIN clients c ON c.id = l.client_id
             LEFT JOIN executions e ON e.id = l.execution_id
             $where
             ORDER BY l.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [self::PER_PAGE, $offset])
        );

        // Dropdown sources (bounded: an internal system has tens of these).
        $clients = $db->rows('SELECT id, name FROM clients ORDER BY name');
        $requests = $db->rows('SELECT id, code FROM requests ORDER BY code');

        View::render('logs/index', [
            'rows' => $rows,
            'f' => $f,
            'events' => self::EVENTS,
            'clients' => $clients,
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'qs' => self::queryString($f),
        ], 'Request logs', 'logs');
    }

    /** @return array<string,mixed> validated filter inputs */
    private static function filters(): array
    {
        $event = strtolower(trim((string)(Http::param('event', 'g') ?? '')));
        $mode = strtolower(trim((string)(Http::param('mode', 'g') ?? '')));
        $date = static function (string $key): string {
            $d = trim((string)(Http::param($key, 'g') ?? ''));
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : '';
        };
        return [
            'client_id' => max(0, (int)(Http::param('client_id', 'g') ?? 0)),
            'request_id' => max(0, (int)(Http::param('request_id', 'g') ?? 0)),
            'event' => in_array($event, self::EVENTS, true) ? $event : '',
            'mode' => in_array($mode, ['delayed', 'instant'], true) ? $mode : '',
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
            'page' => max(1, (int)(Http::param('page', 'g') ?? 1)),
        ];
    }

    /**
     * All filter values are whitelisted or bound as prepared params — no
     * string building of user input anywhere.
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function where(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f['client_id'] > 0) {
            $w[] = 'l.client_id = ?';
            $p[] = $f['client_id'];
        }
        if ($f['request_id'] > 0) {
            $w[] = 'l.request_id = ?';
            $p[] = $f['request_id'];
        }
        if ($f['event'] !== '') {
            $w[] = 'l.event = ?';
            $p[] = $f['event'];
        }
        if ($f['mode'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM requests mf WHERE mf.id = l.request_id AND mf.response_mode = ?)';
            $p[] = $f['mode'];
        }
        if ($f['date_from'] !== '') {
            $w[] = 'l.`timestamp` >= ?';
            $p[] = $f['date_from'] . ' 00:00:00';
        }
        if ($f['date_to'] !== '') {
            $w[] = 'l.`timestamp` <= ?';
            $p[] = $f['date_to'] . ' 23:59:59';
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    /** Filter set as a query string (without page), for pagination links. */
    private static function queryString(array $f): string
    {
        $keep = array_filter([
            'client_id' => $f['client_id'] ?: null,
            'request_id' => $f['request_id'] ?: null,
            'event' => $f['event'] ?: null,
            'mode' => $f['mode'] ?: null,
            'date_from' => $f['date_from'] ?: null,
            'date_to' => $f['date_to'] ?: null,
        ], static fn($v) => $v !== null);
        $s = http_build_query($keep);
        return $s !== '' ? $s . '&' : '';
    }
}
