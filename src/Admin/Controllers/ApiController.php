<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Core\Db;
use Core\Http;
use Core\Str;

/**
 * Small GET-only JSON endpoints under /admin/api/* that the Control Room
 * front-end polls (3-5s, paused when the tab is hidden). Session-gated like
 * every other admin page; every response is no-store. CSRF does not apply —
 * nothing here changes state.
 *
 *   /admin/api/state               whole overview payload (pipeline, KPIs...)
 *   /admin/api/tail?since=1234     request_logs rows after an id (live tail)
 *   /admin/api/call?id=<hex32>     one call: timeline for the inspector drawer
 *   /admin/api/quick               palette index: requests + clients + pages
 */
final class ApiController
{
    public static function dispatch(string $sub): void
    {
        if (Http::isPost() || !in_array($sub, ['state', 'tail', 'call', 'quick'], true)) {
            self::fail(404, 'not_found');
        }
        if (Auth::current() === null) {
            self::fail(401, 'unauthorized');
        }
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        $api = [
            'state' => fn(): array => OverviewData::all(),
            'tail'  => fn(): array => ['rows' => self::tail()],
            'call'  => function (): array {
                $data = self::call();
                if ($data === null) {
                    self::fail(404, 'not_found');
                }
                return $data;
            },
            'quick' => fn(): array => ['items' => self::quickIndex()],
        ];
        echo Str::json($api[$sub]());
        exit;
    }

    private static function fail(int $code, string $err): void
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo Str::json(['error' => ['code' => $err]]);
        exit;
    }

    /**
     * Live tail. With since=0 the freshest rows (reversed to chronological
     * order); with since=N everything newer than that id, ascending.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function tail(): array
    {
        $since = (int)(Http::param('since', 'g') ?? 0);
        $db = Db::instance();

        [$whereSql, $params] = self::tailWhere();

        $sql = 'SELECT l.id, l.timestamp, l.event, l.duration_ms, l.row_count,
                       l.message, l.call_id, l.execution_id,
                       r.code, r.response_mode, cl.name AS client_name
                FROM request_logs l
                LEFT JOIN requests r ON r.id = l.request_id
                LEFT JOIN clients cl ON cl.id = l.client_id';

        if ($since > 0) {
            $rows = $db->rows(
                $sql . " WHERE l.id > ? $whereSql ORDER BY l.id ASC LIMIT 60",
                [$since, ...$params]
            );
        } else {
            // newest 40 in chronological order for the first paint
            $rows = $db->rows(
                $sql . " WHERE 1 = 1 $whereSql ORDER BY l.id DESC LIMIT 40",
                $params
            );
            $rows = array_reverse($rows);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'      => (int)$r['id'],
                'ts'      => substr((string)$r['timestamp'], 11, 8),   // Tehran clock time
                'ts_full' => (string)$r['timestamp'],
                'ev'      => (string)$r['event'],
                'code'    => $r['code'] !== null ? (string)$r['code'] : null,
                'client'  => $r['client_name'] !== null ? (string)$r['client_name'] : null,
                'mode'    => $r['response_mode'] !== null ? (string)$r['response_mode'] : null,
                'dur'     => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
                'rows'    => $r['row_count'] !== null ? (int)$r['row_count'] : null,
                'call'    => $r['call_id'] !== null ? (string)$r['call_id'] : null,
                'exec'    => $r['execution_id'] !== null ? (int)$r['execution_id'] : null,
                'msg'     => Str::truncate((string)($r['message'] ?? ''), 240),
            ];
        }
        return $out;
    }

    /** @return array{0:string,1:array<int,mixed>} filter SQL shared by the tail */
    private static function tailWhere(): array
    {
        $where = '';
        $params = [];
        $ev = (string)(Http::param('event', 'g') ?? '');
        if (preg_match('/^[a-z_]{1,32}$/', $ev) === 1) {
            $where .= ' AND l.event = ?';
            $params[] = $ev;
        }
        foreach (['client_id' => 'l.client_id', 'request_id' => 'l.request_id'] as $k => $col) {
            $v = (string)(Http::param($k, 'g') ?? '');
            if (ctype_digit($v)) {
                $where .= " AND $col = ?";
                $params[] = (int)$v;
            }
        }
        $q = trim((string)(Http::param('q', 'g') ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where .= ' AND (l.message LIKE ? OR r.code LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        return [$where, $params];
    }

    /**
     * Call inspector payload: the call, its request + client, the execution,
     * the log timeline and the webhook delivery state.
     */
    private static function call(): ?array
    {
        $id = (string)(Http::param('g', 'id') ?? '');
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return null;
        }
        $db = Db::instance();
        $call = (array)$db->one(
            'SELECT cl.id, cl.client_id, cl.request_id, cl.execution_id, cl.created_at,
                    cl.ip, cl.was_shared,
                    c.name AS client_name, c.type AS client_type,
                    r.code, r.name AS request_name, r.response_mode
             FROM calls cl
             LEFT JOIN clients c  ON c.id = cl.client_id
             LEFT JOIN requests r ON r.id = cl.request_id
             WHERE cl.id = ?',
            [$id]
        );
        if ($call === [] || $call['id'] === null) {
            return null;
        }

        // merged timeline from request_logs (the append-only trail)
        $logs = [];
        foreach ($db->rows(
            'SELECT l.timestamp, l.event, l.message, l.duration_ms, l.row_count, l.ip
             FROM request_logs l WHERE l.call_id = ? ORDER BY l.id ASC',
            [$id]
        ) as $l) {
            $logs[] = [
                'ts'   => (string)$l['timestamp'],
                'ev'   => (string)$l['event'],
                'msg'  => (string)($l['message'] ?? ''),
                'dur'  => $l['duration_ms'] !== null ? (int)$l['duration_ms'] : null,
                'rows' => $l['row_count'] !== null ? (int)$l['row_count'] : null,
            ];
        }

        $exec = null;
        $whd = null;
        if ($call['execution_id'] !== null) {
            $e = (array)$db->one(
                'SELECT id, status, mode, scheduled_at, started_at, finished_at, ready_at,
                        expires_at, result_path, result_size_bytes, row_count, truncated,
                        error_code, LEFT(error_message, 240) AS error_message
                 FROM executions WHERE id = ?',
                [(int)$call['execution_id']]
            );
            if ($e !== []) {
                $dur = null;
                if ($e['started_at'] !== null && $e['finished_at'] !== null) {
                    $dur = (int)max(0, strtotime((string)$e['finished_at']) - strtotime((string)$e['started_at']));
                }
                $exec = [
                    'id'    => (int)$e['id'],
                    'status'  => (string)$e['status'],
                    'mode'    => (string)$e['mode'],
                    'rows'  => $e['row_count'] !== null ? (int)$e['row_count'] : null,
                    'size'  => $e['result_size_bytes'] !== null ? (int)$e['result_size_bytes'] : null,
                    'trunc'  => (int)$e['truncated'] === 1,
                    'err'  => $e['error_message'] !== null ? (string)$e['error_message'] : null,
                    'err_code' => $e['error_code'] !== null ? (string)$e['error_code'] : null,
                    'dur'   => $dur,
                    'sched'  => $e['scheduled_at'] !== null ? (string)$e['scheduled_at'] : null,
                    'start'  => $e['started_at'] !== null ? (string)$e['started_at'] : null,
                    'fin'    => $e['finished_at'] !== null ? (string)$e['finished_at'] : null,
                    'ready'  => $e['ready_at'] !== null ? (string)$e['ready_at'] : null,
                    'exp'    => $e['expires_at'] !== null ? (string)$e['expires_at'] : null,
                ];
                $wh = (array)$db->one(
                    'SELECT attempt, state, status_code, sent_at, next_retry_at, last_error
                     FROM webhook_deliveries WHERE call_id = ?',
                    [$id]
                );
                if ($wh !== []) {
                    $whd = [
                        'attempt' => (int)$wh['attempt'],
                        'state'   => (string)$wh['state'],
                        'status'   => $wh['status_code'] !== null ? (int)$wh['status_code'] : null,
                        'sent'    => $wh['sent_at'] !== null ? (string)$wh['sent_at'] : null,
                        'retry'   => $wh['next_retry_at'] !== null ? (string)$wh['next_retry_at'] : null,
                        'error'   => $wh['last_error'] !== null ? (string)$wh['last_error'] : null,
                    ];
                }
            }
        }

        return [
            'call'    => [
                'id'    => $id,
                'ts'    => (string)$call['created_at'],
                'ip'    => (string)$call['ip'],
                'shared' => (int)$call['was_shared'] === 1,
                'client' => ['name' => (string)$call['client_name'], 'type' => (string)$call['client_type']],
                'request' => [
                    'code' => (string)$call['code'],
                    'name' => (string)$call['request_name'],
                    'mode' => (string)$call['response_mode'],
                ],
            ],
            'exec'    => $exec,
            'webhook' => $whd,
            'logs'    => $logs,
        ];
    }

    /**
     * Palette index: pages (fixed), then live entities. Group label 'g',
     * title 't', url 'h'. Bounded (200 entities) so old panels stay snappy.
     *
     * @return array<int,array{t:string,h:string,g:string}>
     */
    private static function quickIndex(): array
    {
        $db = Db::instance();
        $items = [];
        foreach ($db->rows(
            'SELECT id, code, name FROM requests ORDER BY code LIMIT 200'
        ) as $r) {
            $items[] = ['t' => 'r/' . $r['code'] . ' — ' . $r['name'], 'h' => Http::url('/admin/requests/' . (int)$r['id']), 'g' => 'Requests'];
        }
        foreach ($db->rows(
            'SELECT id, name FROM clients ORDER BY name LIMIT 100'
        ) as $c) {
            $items[] = ['t' => 'c/' . $c['name'], 'h' => Http::url('/admin/clients/' . (int)$c['id']), 'g' => 'Clients'];
        }
        foreach ($db->rows(
            'SELECT id, name FROM connections ORDER BY name LIMIT 100'
        ) as $cn) {
            $items[] = ['t' => 'cn/' . $cn['name'], 'h' => Http::url('/admin/connections/' . (int)$cn['id']), 'g' => 'Connections'];
        }
        return $items;
    }
}
