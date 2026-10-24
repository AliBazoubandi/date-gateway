<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Flashes;
use Admin\View;
use Core\Config;
use Core\Db;
use Core\Http;
use Core\Str;
use Core\Time;
use Services\AuditLogger;
use Services\InstantExecutor;
use Services\QueryRunner;

final class RequestController
{
    public static function list(): void
    {
        $db = Db::instance();

        // --- filters (GET): q texts, mode/connection/active whitelisted ------
        $f = [
            'q'       => trim((string)(Http::param('q', 'g') ?? '')),
            'mode'    => (string)(Http::param('mode', 'g') ?? ''),
            'conn'    => (string)(Http::param('connection', 'g') ?? ''),
            'active'  => (string)(Http::param('active', 'g') ?? ''),
        ];
        $f['mode']   = in_array($f['mode'], ['delayed', 'instant'], true) ? $f['mode'] : '';
        $f['active'] = in_array($f['active'], ['0', '1'], true) ? $f['active'] : '';

        $where = '';
        $params = [];
        if ($f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $where .= ' WHERE (r.code LIKE ? OR r.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($f['mode'] !== '') {
            $where .= ($where === '' ? ' WHERE' : ' AND') . ' r.response_mode = ?';
            $params[] = $f['mode'];
        }
        if (ctype_digit($f['conn']) && (int)$f['conn'] > 0) {
            $where .= ($where === '' ? ' WHERE' : ' AND') . ' r.connection_id = ?';
            $params[] = (int)$f['conn'];
        }
        if ($f['active'] !== '') {
            $where .= ($where === '' ? ' WHERE' : ' AND') . ' r.is_active = ?';
            $params[] = (int)$f['active'];
        }

        $rows = $db->rows(
            'SELECT r.id, r.code, r.name, r.response_mode, r.is_active, r.timeout_seconds, r.max_rows,
                    r.delay_minutes, r.ttl_minutes, r.instant_cache_seconds,
                    r.updated_at, r.updated_by,
                    c.name AS connection_name, c.db_type,
                    (SELECT COUNT(*) FROM executions e WHERE e.request_id = r.id) AS runs_total
             FROM requests r
             JOIN connections c ON c.id = r.connection_id'
            . $where
            . ' ORDER BY r.code',
            $params
        );

        // Request name for each connection select option + the filter UI.
        $connections = $db->rows('SELECT id, name, db_type FROM connections ORDER BY name');

        // Latest 8 durations per request (as comma text, index 0 = newest)
        // from the append-only log trail — the sparkline data source.
        $sparks = [];
        foreach ($db->rows(
            'SELECT t.request_id, GROUP_CONCAT(t.dur) AS ds
             FROM (
                 SELECT request_id, duration_ms AS dur,
                        ROW_NUMBER() OVER (PARTITION BY request_id ORDER BY id DESC) AS rn
                 FROM request_logs
                 WHERE duration_ms IS NOT NULL
                   AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ) t
             WHERE t.rn <= 8
             GROUP BY t.request_id'
        ) as $s) {
            $sparks[(int)$s['request_id']] = array_map(
                static fn($v): int => (int)$v,
                explode(',', (string)$s['ds'])
            );
        }

        // "last changed by X at Y" names come through $rows.updated_by; the
        // page resolves them to usernames in a single pass if wanted.
        View::render('requests/list', [
            'rows' => $rows,
            'sparks' => $sparks,
            'f' => $f,
            'connections' => $connections,
        ], 'Requests', 'requests-list');
    }

    /** @param int $id 0 = new */
    public static function edit(int $id): void
    {
        $db = Db::instance();
        $row = $id > 0 ? $db->one('SELECT * FROM requests WHERE id = ?', [$id]) : null;
        if ($row === null && $id > 0) {
            Flashes::set('error', 'Request not found.');
            Http::redirect('/admin/requests');
        }
        $connections = $db->rows('SELECT id, name, db_type, is_active FROM connections ORDER BY name');

        // presentation-only: "last changed by X at Y" (schema updated_by)
        $changed = null;
        if ($row !== null && (int)$row['updated_by'] > 0) {
            $by = $db->value('SELECT username FROM admin_users WHERE id = ?', [(int)$row['updated_by']]);
            if ($by !== null) {
                $changed = ['by' => (string)$by, 'at' => (string)($row['updated_at'] ?? '')];
            }
        }
        View::render('requests/edit', ['row' => $row ?? self::defaults(), 'connections' => $connections,
            'changed' => $changed],
            $row === null ? 'New request' : 'Edit request', 'request-edit');
    }

    public static function save(int $id): void
    {
        $db = Db::instance();
        $in = self::input();
        $errors = self::validate($in, $id);
        if ($errors !== []) {
            // Re-render with everything the admin typed plus a red border on
            // the offending field — a redirect would blank the whole form.
            View::render('requests/edit', [
                'row' => self::rowFromInput($in, $id),
                'connections' => $db->rows('SELECT id, name, db_type, is_active FROM connections ORDER BY name'),
                'errors' => $errors,
            ], $id > 0 ? 'Edit request' : 'New request', 'request-edit');
            return;
        }
        $adminId = (int)($_SESSION['admin_user_id'] ?? 0);
        $now = Time::now();

        if ($id > 0) {
            $before = $db->one('SELECT * FROM requests WHERE id = ?', [$id]);
            $db->execute(
                'UPDATE requests SET code=?, name=?, description=?, sql_text=?, params_json=?, connection_id=?,
                    response_mode=?, instant_cache_seconds=?, delay_minutes=?, ttl_minutes=?,
                    timeout_seconds=?, max_rows=?, is_active=?, updated_by=?, updated_at=?
                 WHERE id=?',
                [$in['code'], $in['name'], $in['description'], $in['sql_text'], $in['params_json'], $in['connection_id'],
                    $in['response_mode'], $in['instant_cache_seconds'], $in['delay_minutes'],
                    $in['ttl_minutes'], $in['timeout_seconds'], $in['max_rows'], $in['is_active'],
                    $adminId, $now, $id]
            );
            $after = $db->one('SELECT * FROM requests WHERE id = ?', [$id]);
            AuditLogger::record('request', 'update', $id, $in['code'], $before, $after);
            Flashes::set('ok', 'Request updated. New calls use the new definition immediately.');
        } else {
            $db->execute(
                'INSERT INTO requests (code, name, description, sql_text, params_json, connection_id, response_mode,
                    instant_cache_seconds, delay_minutes, ttl_minutes, timeout_seconds, max_rows,
                    is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$in['code'], $in['name'], $in['description'], $in['sql_text'], $in['params_json'], $in['connection_id'],
                    $in['response_mode'], $in['instant_cache_seconds'], $in['delay_minutes'],
                    $in['ttl_minutes'], $in['timeout_seconds'], $in['max_rows'], $in['is_active'],
                    $adminId, $now]
            );
            $id = $db->insertId();
            $after = $db->one('SELECT * FROM requests WHERE id = ?', [$id]);
            AuditLogger::record('request', 'create', $id, $in['code'], null, $after);
            Flashes::set('ok', 'Request created. Grant permissions to clients next.');
        }
        Http::redirect('/admin/requests');
    }

    public static function delete(int $id): void
    {
        $db = Db::instance();
        $row = $db->one('SELECT * FROM requests WHERE id = ?', [$id]);
        if ($row === null) {
            Flashes::set('error', 'Request not found.');
            Http::redirect('/admin/requests');
        }
        $history = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$id]);
        if ($history > 0) {
            Flashes::set('error', "Request has {$history} execution(s) of history. Deactivate it instead of deleting.");
            Http::redirect('/admin/requests');
        }
        $db->execute('DELETE FROM requests WHERE id = ?', [$id]);
        AuditLogger::record('request', 'delete', $id, (string)$row['code'], self::snapshot($row), null);
        Flashes::set('ok', 'Request deleted.');
        Http::redirect('/admin/requests');
    }

    /** Low-cost live sample of the SQL: <=20 rows, short timeout, audit-logged. */
    public static function testRun(int $id): void
    {
        $db = Db::instance();
        $request = $db->one(
            'SELECT r.*, c.name AS connection_name
             FROM requests r JOIN connections c ON c.id = r.connection_id
             WHERE r.id = ?',
            [$id]
        );
        $connection = $request !== null
            ? $db->one('SELECT * FROM connections WHERE id = ? AND is_active = 1', [$request['connection_id']])
            : null;
        if ($request === null || $connection === null) {
            Flashes::set('error', 'Request or its connection missing/inactive.');
            Http::redirect('/admin/requests');
        }

        $limitRows = (int)Config::get('admin.test_run.max_rows', 20);
        $limitSeconds = (int)Config::get('admin.test_run.max_seconds', 10);
        $preview = [];

        // Resolve test parameters if this query is parameterized
        $schema = \Services\QueryParameters::normalizeSchema($request['params_json'] ?? null, (string)$request['sql_text']);
        $testParams = [];
        if (!empty($schema)) {
            $submitted = [];
            foreach ($_POST as $k => $v) {
                if (str_starts_with($k, 'param_') && is_scalar($v)) {
                    $submitted[substr($k, 6)] = (string)$v;
                }
            }
            if (isset($_POST['params']) && is_array($_POST['params'])) {
                foreach ($_POST['params'] as $k => $v) {
                    if (is_scalar($v)) {
                        $submitted[(string)$k] = (string)$v;
                    }
                }
            }
            // For any missing required parameters, supply sensible test fallbacks if not resolved by defaults
            foreach ($schema as $name => $meta) {
                if (!isset($submitted[$name]) || $submitted[$name] === '') {
                    if ($meta['default'] === null && !empty($meta['required'])) {
                        $submitted[$name] = match ($meta['type'] ?? 'string') {
                            'date' => date('Y-m-d'),
                            'datetime' => date('Y-m-d H:i:s'),
                            'int' => '1',
                            'float' => '1.0',
                            'bool' => '1',
                            default => 'test',
                        };
                    }
                }
            }
            try {
                $testParams = \Services\QueryParameters::resolveClientParams($schema, $submitted);
            } catch (\Services\QueryParameterException $e) {
                $testParams = [];
            }
        }

        $result = QueryRunner::run(
            $connection,
            (string)$request['sql_text'],
            $limitSeconds,
            $limitRows,
            static function (array $row) use (&$preview): void {
                // Trim long values so the preview stays readable and small.
                $trimmed = [];
                foreach ($row as $k => $v) {
                    $str = (string)(is_scalar($v) ? $v : json_encode($v));
                    $trimmed[$k] = Str::truncate($str, 120);
                }
                $preview[] = $trimmed;
            },
            $testParams
        );

        AuditLogger::record('request', 'test_run', (int)$request['id'], (string)$request['code'], null, [
            'connection' => (string)$connection['name'],
            'row_limit' => $limitRows,
            'timeout_seconds' => $limitSeconds,
            'rows_returned' => $result['row_count'],
            'truncated' => $result['truncated'],
            'ok' => $result['ok'],
            'timed_out' => $result['timed_out'],
            'error' => $result['error'] !== '' ? Str::truncate($result['error'], 300) : '',
            'duration_ms' => $result['duration_ms'],
        ]);

        View::render('requests/test', [
            'request' => $request,
            'result' => $result,
            'preview' => $preview,
            'schema' => $schema,
            'test_params' => $testParams,
            'limits' => 'first ' . $limitRows . ' rows, ' . $limitSeconds . 's timeout',
        ], 'Test run', 'request-test');
    }

    /** ------------------------------------------------------------------ */

    private static function defaults(): array
    {
        $delayedMax = (int)Config::get('limits.default.max_rows', 100000);
        $instant = (array)Config::get('limits.instant.defaults', []);
        return [
            'id' => 0, 'code' => '', 'name' => '', 'description' => '', 'sql_text' => '',
            'connection_id' => 0, 'response_mode' => 'delayed',
            'instant_cache_seconds' => 0, 'delay_minutes' => 0, 'ttl_minutes' => 15,
            'timeout_seconds' => 60, 'max_rows' => $delayedMax, 'is_active' => 1,
            'instant_defaults' => $instant, 'params_json' => '',
        ];
    }

    private static function input(): array
    {
        $mode = strtolower(trim((string)Http::param('response_mode', 'p')));
        $mode = in_array($mode, ['delayed', 'instant'], true) ? $mode : 'delayed';
        $inst = (array)Config::get('limits.instant', []);
        if ($mode === 'instant') {
            // Clamp instant requests to the sane configured upper bounds.
            $maxTimeout = (int)($inst['max_timeout_seconds'] ?? 60);
            $maxRows = (int)($inst['max_rows'] ?? 20000);
            if ($maxTimeout <= 0) {
                $maxTimeout = 60;
            }
            if ($maxRows <= 0) {
                $maxRows = 20000;
            }
        } else {
            // 30 min max: must stay well under worker.stale_running_minutes (90)
            // so a legitimately long query is never mistaken for a crashed one.
            $maxTimeout = 1800;
            $maxRows = 10000000;
        }
        $int = static function (string $key, string $src, int $min, int $max, int $default): int {
            $raw = trim((string)(Http::param($key, $src) ?? ''));
            $n = ($raw === '') ? $default : (int)$raw;
            return min(max($n, $min), $max);
        };
        // A cache window is only servable while the cached result file lives
        // (limits.instant.result_ttl_minutes), so the form cannot offer more
        // than the sweep can keep. 86400 stays as a sanity ceiling.
        $cacheMax = min(86400, InstantExecutor::resultTtlMinutes() * 60);
        $cache = $int('instant_cache_seconds', 'p', 0, $cacheMax, 0);

        $sqlText = (string)Http::param('sql_text', 'p');
        $rawParamsJson = trim((string)Http::param('params_json', 'p'));
        $schema = \Services\QueryParameters::normalizeSchema($rawParamsJson !== '' ? $rawParamsJson : null, $sqlText);
        $finalParamsJson = !empty($schema) ? json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : null;

        return [
            'code' => trim((string)Http::param('code', 'p')),
            'name' => trim((string)Http::param('name', 'p')),
            'description' => trim((string)Http::param('description', 'p')),
            'sql_text' => $sqlText,
            'params_json' => $finalParamsJson,
            // POST only: a query-string connection_id would otherwise retarget
            // the saved request behind the select the admin can see.
            'connection_id' => (int)trim((string)(Http::param('connection_id', 'p') ?? '')),
            'response_mode' => $mode,
            'instant_cache_seconds' => $mode === 'instant' ? $cache : 0,
            'delay_minutes' => $mode === 'delayed' ? $int('delay_minutes', 'p', 0, 10080, 0) : 0,
            'ttl_minutes' => $mode === 'delayed' ? $int('ttl_minutes', 'p', 1, 43200, 15) : 0,
            'timeout_seconds' => $int('timeout_seconds', 'p', 1, $maxTimeout, $mode === 'instant' ? (int)($inst['defaults']['timeout_seconds'] ?? 30) : 60),
            'max_rows' => $int('max_rows', 'p', 1, $maxRows, $mode === 'instant' ? (int)($inst['defaults']['max_rows'] ?? 5000) : 100000),
            'is_active' => Http::param('is_active', 'p') !== null ? 1 : 0,
        ];
    }

    /** The shape requests/edit.php renders, rebuilt from the POST input. */
    private static function rowFromInput(array $in, int $id): array
    {
        return ['id' => $id, 'instant_defaults' => (array)Config::get('limits.instant.defaults', [])] + $in;
    }

    /** @return array<string,string> field name => error message */
    private static function validate(array $in, int $id): array
    {
        $db = Db::instance();
        $e = [];
        if (!Str::isCode($in['code'])) {
            $e['code'] = 'Code must be 1-64 chars of [A-Za-z0-9._-].';
        }
        $dupe = $db->value('SELECT id FROM requests WHERE code = ? AND id <> ?', [$in['code'], $id]);
        if ($dupe !== null) {
            $e['code'] = 'Code already exists for another request.';
        }
        if ($in['name'] === '') {
            $e['name'] = 'Name is required.';
        }
        if (trim($in['sql_text']) === '') {
            $e['sql_text'] = 'SQL text is required.';
        } else {
            $conn = $db->one('SELECT id, db_type, allow_writes FROM connections WHERE id = ?', [$in['connection_id']]);
            if ($conn === null) {
                $e['connection_id'] = 'Pick a connection.';
            }
            if (!isset($e['connection_id'])) {
                try {
                    \Services\SqlGuard::assert($in['sql_text'], (string)$conn['db_type'], !empty($conn['allow_writes']));
                } catch (\RuntimeException $ex) {
                    $e['sql_text'] = !empty($conn['allow_writes'])
                        ? 'SQL rejected by the script policy (UPDATE and DELETE statements stay blocked): ' . $ex->getMessage()
                        : 'SQL must be a single read-only SELECT statement. ' . $ex->getMessage();
                }
            }
        }
        return $e;
    }

    private static function snapshot(?array $row): array
    {
        return $row === null ? [] : $row;
    }
}
