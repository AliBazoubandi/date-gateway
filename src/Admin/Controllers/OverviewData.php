<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Core\Config;
use Core\Db;
use Core\Time;
use Services\WorkerState;

/**
 * The Overview "state": KPI strip, pipeline lanes, connection load,
 * 7x24 activity heatmap, recent failures and the worker heartbeat.
 *
 * The overview page renders the first paint from this array AND
 * GET /admin/api/state re-serializes the same array every few seconds,
 * so the poller and the server markup always agree on shape.
 *
 * Durations: executions hold no duration column — the per-request duration
 * trail lives in request_logs.duration_ms, and lane cards derive elapsed
 * time from started/finished timestamps with TIMESTAMPDIFF() here.
 */
final class OverviewData
{
    private const LANE_LIMIT = 6;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $db = Db::instance();

        return [
            'now'      => now_str(),
            'kpis'      => self::kpis($db),
            'lanes'     => self::lanes($db),
            'connections' => self::connections($db),
            'heatmap'   => self::heatmap($db),
            'fails'     => self::fails($db),
            'heartbeat' => self::heartbeat(),
        ];
    }

    /**
     * KPI strip. One query per cluster; all constants, no user input.
     *
     * @param \Core\Db $db
     * @return array<string,mixed>
     */
    private static function kpis(\Core\Db $db): array
    {
        $c = (array)$db->one(
            "SELECT
                (SELECT COUNT(*) FROM calls WHERE created_at >= CURDATE()) AS calls_today,
                (SELECT COUNT(*) FROM executions WHERE status = 'scheduled') AS scheduled,
                (SELECT COUNT(*) FROM executions WHERE status = 'running') AS running,
                (SELECT COUNT(*) FROM executions WHERE status = 'ready') AS ready,
                (SELECT COUNT(*) FROM executions WHERE status = 'expired') AS expired,
                (SELECT COUNT(*) FROM executions
                    WHERE finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'ready') AS ok_24h,
                (SELECT COUNT(*) FROM executions
                    WHERE finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'failed') AS bad_24h,
                (SELECT COUNT(*) FROM executions
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND mode = 'instant') AS inst_24h,
                (SELECT COUNT(*) FROM executions
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND mode = 'delayed') AS del_24h"
        );

        // calls/hour for the last 24 buckets (sparse -> filled to 24 points)
        $spark = array_fill(0, 24, 0);
        $rows = $db->rows(
            'SELECT FLOOR(UNIX_TIMESTAMP(created_at) / 3600) AS b, COUNT(*) AS n
             FROM calls
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY b ORDER BY b'
        );
        $nowHour = (int)floor(time() / 3600);
        foreach ($rows as $r) {
            $off = $nowHour - (int)$r['b'];
            if ($off >= 0 && $off < 24) {
                $spark[23 - $off] = (int)$r['n'];
            }
        }

        $ok = (int)$c['ok_24h'];
        $bad = (int)$c['bad_24h'];

        return [
            'calls_today' => (int)$c['calls_today'],
            'running'     => (int)$c['running'],
            'scheduled'   => (int)$c['scheduled'],
            'ready'       => (int)$c['ready'],
            'expired'     => (int)$c['expired'],
            'ok_24h'      => $ok,
            'bad_24h'     => $bad,
            'rate_24h'    => ($ok + $bad) > 0 ? (int)round($ok * 100 / ($ok + $bad)) : null,
            'inst_24h'    => (int)$c['inst_24h'],
            'del_24h'     => (int)$c['del_24h'],
            'spark'       => $spark,
            'spark_max'   => max(1, max($spark)),
        ];
    }

    /** Pipeline lanes: 6 cards each, per the lanes' own sort.
     * @param \Core\Db $db
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function lanes(\Core\Db $db): array
    {
        $base = 'SELECT e.id, e.mode, e.status, e.scheduled_at, e.started_at, e.finished_at,
                        e.ready_at, e.expires_at, e.row_count, e.error_code, LEFT(e.error_message, 240) AS error_message,
                        r.code, r.name, r.response_mode, r.timeout_seconds,
                        c.name AS conn_name, c.db_type
                 FROM executions e
                 JOIN requests r    ON r.id = e.request_id
                 JOIN connections c ON c.id = r.connection_id';

        $cards = function (array $rows): array {
            $out = [];
            foreach ($rows as $r) {
                $anchor = null;        // what the countdown counts to
                $kind   = null;        // 'to-run' | 'to-expiry' | 'elapsed'
                if ($r['status'] === 'scheduled' && $r['scheduled_at'] !== null) {
                    $kind = 'to-run';
                    $anchor = (int)max(0, strtotime((string)$r['scheduled_at']) - time());
                } elseif ($r['status'] === 'ready' && $r['expires_at'] !== null) {
                    $kind = 'to-expiry';
                    $anchor = (int)max(0, strtotime((string)$r['expires_at']) - time());
                } elseif ($r['status'] === 'running' && $r['started_at'] !== null) {
                    $kind = 'elapsed';
                    $anchor = (int)max(0, time() - strtotime((string)$r['started_at']));
                }
                $dur = null;
                if (($r['status'] === 'ready' || $r['status'] === 'expired' || $r['status'] === 'failed')
                    && $r['started_at'] !== null && $r['finished_at'] !== null) {
                    $dur = (int)max(0, strtotime((string)$r['finished_at']) - strtotime((string)$r['started_at']));
                }
                $out[] = [
                    'id'      => (int)$r['id'],
                    'code'    => (string)$r['code'],
                    'name'    => (string)$r['name'],
                    'mode'    => (string)$r['mode'],
                    'status'  => (string)$r['status'],
                    'conn'    => (string)$r['conn_name'],
                    'db'      => (string)$r['db_type'],
                    'rows'    => $r['row_count'] !== null ? (int)$r['row_count'] : null,
                    'err'     => $r['error_message'] !== null ? (string)$r['error_message'] : null,
                    'err_code' => $r['error_code'] !== null ? (string)$r['error_code'] : null,
                    'when'    => (string)($r['scheduled_at'] ?? $r['started_at'] ?? $r['ready_at'] ?? $r['expires_at'] ?? ''),
                    'remains' => $anchor,
                    'span'    => $kind === 'elapsed' ? max(1, (int)($r['timeout_seconds'] ?? 60)) : null,
                    'ring'    => $kind,
                    'dur'     => $dur,
                ];
            }
            return $out;
        };

        // MariaDB supports the same scalar subselect style used elsewhere.
        return [
            'scheduled' => $cards($db->rows($base
                . " WHERE e.status = 'scheduled'
                   ORDER BY e.scheduled_at ASC, e.id ASC LIMIT " . self::LANE_LIMIT)),
            'running'   => $cards($db->rows($base
                . " WHERE e.status = 'running'
                   ORDER BY e.started_at ASC, e.id ASC LIMIT " . self::LANE_LIMIT)),
            'ready'     => $cards($db->rows($base
                . " WHERE e.status = 'ready'
                   ORDER BY e.expires_at ASC, e.id ASC LIMIT " . self::LANE_LIMIT)),
            'expired'   => $cards($db->rows($base
                . " WHERE e.status = 'expired'
                   ORDER BY e.expires_at DESC, e.id DESC LIMIT " . self::LANE_LIMIT)),
        ];
    }

    /** Connection load rings: running executions per connection vs its cap.
     * @param \Core\Db $db
     * @return array<int,array<string,mixed>>
     */
    private static function connections(\Core\Db $db): array
    {
        $default = (int)Config::get('limits.connection_concurrency', 5);
        $rows = $db->rows(
            'SELECT c.id, c.name, c.db_type, c.db_name, c.host, c.is_active, c.max_concurrency,
                    (SELECT COUNT(*) FROM executions e
                        JOIN requests r ON r.id = e.request_id
                        WHERE e.status = \'running\' AND r.connection_id = c.id) AS running_now,
                    (SELECT COUNT(*) FROM requests r
                        WHERE r.connection_id = c.id AND r.is_active = 1) AS active_requests
             FROM connections c
             ORDER BY c.name'
        );
        $out = [];
        foreach ($rows as $r) {
            $cap = $r['max_concurrency'] !== null ? (int)$r['max_concurrency'] : $default;
            $running = (int)$r['running_now'];
            $out[] = [
                'id'       => (int)$r['id'],
                'name'     => (string)$r['name'],
                'db'       => (string)$r['db_type'],
                'db_name'  => (string)$r['db_name'],
                'host'     => (string)$r['host'],
                'active'   => (int)$r['is_active'] === 1,
                'cap'      => $cap,
                'running'  => $running,
                'requests' => (int)$r['active_requests'],
                'pct'      => $cap > 0 ? min(100, (int)round($running * 100 / $cap)) : 0,
                'hot'      => $cap > 0 && $running >= $cap,
            ];
        }
        return $out;
    }

    /** 7x24 heatmap of executions started (count + failures per cell).
     * @param \Core\Db $db
     * @return array{cells:array<array<int,int>>,fails:array<array<int,int>>,peak:int,day:int,hour:int}
     */
    private static function heatmap(\Core\Db $db): array
    {
        $cells = array_fill(0, 7, array_fill(0, 24, 0));
        $fails = array_fill(0, 7, array_fill(0, 24, 0));
        $rows = $db->rows(
            'SELECT WEEKDAY(started_at) AS d, HOUR(started_at) AS h,
                    COUNT(*) AS n, SUM(status = \'failed\') AS f
             FROM executions
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY d, h'
        );
        $peak = 1;
        foreach ($rows as $r) {
            $d = (int)$r['d'];
            $h = (int)$r['h'];
            if ($d >= 0 && $d < 7 && $h >= 0 && $h < 24) {
                $cells[$d][$h] = (int)$r['n'];
                $fails[$d][$h] = (int)$r['f'];
                $peak = max($peak, (int)$r['n']);
            }
        }
        return [
            'cells' => $cells,
            'fails' => $fails,
            'peak'  => $peak,
            'day'   => (int)date('N') - 1,   // 0 = Monday
            'hour'  => (int)date('G'),
        ];
    }

    /** Failures feed: last 5 (code, error, when).
     * @param \Core\Db $db
     * @return array<int,array<string,mixed>>
     */
    private static function fails(\Core\Db $db): array
    {
        $rows = $db->rows(
            'SELECT e.id, e.error_code, LEFT(e.error_message, 200) AS error_message, e.finished_at,
                    r.code
             FROM executions e
             JOIN requests r ON r.id = e.request_id
             WHERE e.status = \'failed\'
             ORDER BY e.id DESC LIMIT 5'
        );
        $out = [];
        $i = 0;
        foreach ($rows as $r) {
            $when = (string)$r['finished_at'];
            $out[] = [
                'id'    => (int)$r['id'],
                'code'  => (string)$r['code'],
                'err'   => $r['error_code'] !== null ? (string)$r['error_code'] : null,
                'msg'   => $r['error_message'] !== null ? (string)$r['error_message'] : null,
                'when'  => $when !== '' ? $when : null,
                'age'   => $when !== '' ? (int)max(0, time() - strtotime($when)) : null,
                'ago'   => self::ago($when !== '' ? (int)max(0, time() - strtotime($when)) : 0),
            ];
        }
        return $out;
    }

    /** Worker heartbeat (green <120s, amber <300s, coral beyond). */
    private static function heartbeat(): array
    {
        $pass = WorkerState::lastPass();
        $age = WorkerState::passAgeSeconds($pass);
        $row = WorkerState::lastPass();
        $state = 'bad';
        if ($age !== null && $age < 120) {
            $state = 'ok';
        } elseif ($age !== null && $age < 300) {
            $state = 'warn';
        }
        return [
            'state'     => $state,
            'age'       => $age,
            'label'     => $age !== null ? self::ago($age) : null,
            'failures'  => $row !== null && isset($row['last_pass_failures']) ? (int)$row['last_pass_failures'] : null,
        ];
    }

    private static function ago(?int $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = max(0, $s);
        if ($s < 60) {
            return $s . 's ago';
        }
        $m = (int)floor($s / 60);
        if ($m < 60) {
            return $m . 'm ago';
        }
        $h = (int)floor($m / 60);
        if ($h < 48) {
            return $h . 'h ' . ($m % 60) . 'm ago';
        }
        return (string)(int)floor($h / 24) . 'd ago';
    }
}
