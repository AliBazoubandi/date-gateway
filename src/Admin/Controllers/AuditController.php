<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\View;
use Core\Db;
use Core\Http;
use Core\Str;

/**
 * Read-only viewer for admin_audit_log (append-only). Snapshots hold FULL
 * records; this page derives exactly the changed fields and shows them as a
 * readable before/after table (for create/delete/test_run, the single
 * available snapshot is listed).
 */
final class AuditController
{
    private const PER_PAGE = 50;

    private const ENTITIES = ['request', 'connection', 'client', 'permission', 'admin'];
    private const ACTIONS = ['create', 'update', 'delete', 'test_run',
        'login', 'login_failed', 'logout', 'password_change'];

    public static function index(): void
    {
        $db = Db::instance();
        $f = self::filters();
        [$where, $params] = self::where($f);

        $total = (int)$db->value("SELECT COUNT(*) FROM admin_audit_log a $where", $params);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min(max(1, $f['page']), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $db->rows(
            "SELECT a.id, a.`timestamp`, a.entity_type, a.entity_id, a.entity_name, a.action,
                    a.before_json, a.after_json, u.username AS admin_name
             FROM admin_audit_log a
             LEFT JOIN admin_users u ON u.id = a.admin_user_id
             $where
             ORDER BY a.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [self::PER_PAGE, $offset])
        );

        // Precompute the readable diff per row so the view stays dumb.
        $items = [];
        foreach ($rows as $row) {
            $before = self::decode((string)($row['before_json'] ?? ''));
            $after = self::decode((string)($row['after_json'] ?? ''));
            $items[] = [
                'row' => $row,
                'diff' => self::diff($before, $after),
            ];
        }

        $admins = $db->rows('SELECT id, username FROM admin_users ORDER BY username');

        View::render('audit/index', [
            'items' => $items,
            'f' => $f,
            'entities' => self::ENTITIES,
            'actions' => self::ACTIONS,
            'admins' => $admins,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'qs' => self::queryString($f),
        ], 'Audit log');
    }

    /** @return array<string,mixed> validated filter inputs */
    private static function filters(): array
    {
        $entity = strtolower(trim((string)(Http::param('entity_type', 'g') ?? '')));
        $action = strtolower(trim((string)(Http::param('action', 'g') ?? '')));
        $date = static function (string $key): string {
            $d = trim((string)(Http::param($key, 'g') ?? ''));
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : '';
        };
        return [
            'entity_type' => in_array($entity, self::ENTITIES, true) ? $entity : '',
            'action' => in_array($action, self::ACTIONS, true) ? $action : '',
            'admin_user_id' => max(0, (int)(Http::param('admin_user_id', 'g') ?? 0)),
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
            'page' => max(1, (int)(Http::param('page', 'g') ?? 1)),
        ];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function where(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f['entity_type'] !== '') {
            $w[] = 'a.entity_type = ?';
            $p[] = $f['entity_type'];
        }
        if ($f['action'] !== '') {
            $w[] = 'a.action = ?';
            $p[] = $f['action'];
        }
        if ($f['admin_user_id'] > 0) {
            $w[] = 'a.admin_user_id = ?';
            $p[] = $f['admin_user_id'];
        }
        if ($f['date_from'] !== '') {
            $w[] = 'a.`timestamp` >= ?';
            $p[] = $f['date_from'] . ' 00:00:00';
        }
        if ($f['date_to'] !== '') {
            $w[] = 'a.`timestamp` <= ?';
            $p[] = $f['date_to'] . ' 23:59:59';
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    private static function queryString(array $f): string
    {
        $keep = array_filter([
            'entity_type' => $f['entity_type'] ?: null,
            'action' => $f['action'] ?: null,
            'admin_user_id' => $f['admin_user_id'] ?: null,
            'date_from' => $f['date_from'] ?: null,
            'date_to' => $f['date_to'] ?: null,
        ], static fn($v) => $v !== null);
        $s = http_build_query($keep);
        return $s !== '' ? $s . '&' : '';
    }

    /** ------------------------------------------------------------------
     * Diff building
     * ------------------------------------------------------------------ */

    /** @return array<int,array{field:string,before:string,after:string}> */
    private static function diff(?array $before, ?array $after): array
    {
        if ($before === null && $after === null) {
            return [];
        }
        if ($before === null) { // create / test_run: the after snapshot is the event
            return self::single($after, 'after');
        }
        if ($after === null) { // delete: the before snapshot is what is gone
            return self::single($before, 'before');
        }
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $k) {
            $b = self::val($before[$k] ?? null);
            $a = self::val($after[$k] ?? null);
            if ($b !== $a) {
                $out[] = ['field' => $k, 'before' => $b, 'after' => $a];
            }
        }
        return $out;
    }

    /** @return array<int,array{field:string,before:string,after:string}> */
    private static function single(?array $snapshot, string $side): array
    {
        $out = [];
        foreach ($snapshot ?? [] as $k => $v) {
            $out[] = [
                'field' => (string)$k,
                'before' => $side === 'before' ? self::val($v) : '',
                'after' => $side === 'after' ? self::val($v) : '',
            ];
        }
        return $out;
    }

    /** Normalize a snapshot value to a comparable, displayable string. */
    private static function val($v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return Str::json($v);
    }

    private static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }
}
