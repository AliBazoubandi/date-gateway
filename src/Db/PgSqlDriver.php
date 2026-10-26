<?php
declare(strict_types=1);
namespace Db;

/**
 * PostgreSQL driver via the native pg_* extension (no PDO).
 *
 * SELECT/WITH queries run inside a READ ONLY transaction behind a server-side
 * cursor and are fetched in batches, so memory stays bounded for huge result
 * sets. Only EXPLAIN/SHOW may take the direct path; every other statement is
 * refused here as well as by SqlGuard (P1-6).
 */
final class PgSqlDriver implements DriverInterface
{
    private const BATCH = 1000;

    /** @var resource|null */
    private $conn = null;
    /** @var resource|null */
    private $result = null;
    /** @var array<int,array<string,mixed>>|null */
    private ?array $buffer = null;
    private ?string $cursor = null;
    private int $timeout = 30;
    private string $lastError = '';
    private bool $timedOut = false;
    private static int $seq = 0;

    public function connect(array $o): void
    {
        if (!function_exists('pg_connect')) {
            throw new DbConnectException(
                'The pgsql extension is not enabled in php.ini (extension=php_pgsql.dll).'
            );
        }
        $parts = [
            'host=' . $this->quote((string)$o['host']),
            'dbname=' . $this->quote((string)$o['db']),
            'user=' . $this->quote((string)$o['user']),
            'password=' . $this->quote((string)$o['password']),
            'connect_timeout=15',
        ];
        if (($o['port'] ?? 0) > 0) {
            $parts[] = 'port=' . (int)$o['port'];
        }
        $beforeErr = error_get_last();
        $conn = @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $err = error_get_last();
            $msg = ($err !== null && $err !== $beforeErr && !empty($err['message']))
                ? preg_replace('/^pg_connect\(\):\s*/i', '', (string)$err['message'])
                : 'unknown PostgreSQL connection failure';
            $this->lastError = $msg;
            $this->timedOut = $this->isTimeoutText($msg);
            throw new DbConnectException('PostgreSQL connect failed: ' . $msg);
        }
        $this->conn = $conn;
        $this->lastError = '';
        $this->timedOut = false;
        // Tehran everywhere + per-session statement timeout.
        @pg_query($this->conn, "SET client_encoding TO 'UTF8'");
        $this->applySessionTimeout();
    }

    public function setQueryTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
        if ($this->conn !== null) {
            $this->applySessionTimeout();
        }
    }

    public function query(string $sql, array $params = []): mixed
    {
        $sql = rtrim(trim($sql), ';');
        if (preg_match('/^(select|with)\b/i', $sql) === 1) {
            // READ ONLY (P1-6): defense in depth behind SqlGuard - a statement
            // that somehow slips past the scanner still cannot write. The
            // transaction ends in free() (COMMIT) or close() (ROLLBACK).
            if (!@pg_query($this->conn, 'BEGIN READ ONLY')) {
                throw new DbQueryException($this->connError(), $this->timedOut);
            }
            $name = 'gwcur_' . ++self::$seq . '_' . bin2hex(random_bytes(4));
            $cursorSql = 'DECLARE ' . $name . ' CURSOR FOR (' . $sql . ')';
            $ok = $params === []
                ? @pg_query($this->conn, $cursorSql)
                : @pg_query_params($this->conn, $cursorSql, $params);
            if (!$ok) {
                @pg_query($this->conn, 'ROLLBACK');
                throw new DbQueryException($this->connError(), $this->timedOut);
            }
            $this->cursor = $name;
            $this->buffer = null;
            return ['type' => 'cursor', 'name' => $name];
        }
        // Non-cursor statements are limited to planner/catalog introspection.
        // Anything else must not reach libpq raw, even if the scanner is
        // bypassed; EXPLAIN ANALYZE is already refused by SqlGuard because it
        // executes the wrapped statement.
        if (preg_match('/^(show|explain)\b/i', $sql) !== 1) {
            throw new DbQueryException('Statement is not a read-only query or EXPLAIN/SHOW', false);
        }
        $res = $params === []
            ? @pg_query($this->conn, $sql)
            : @pg_query_params($this->conn, $sql, $params);
        if ($res === false) {
            throw new DbQueryException($this->connError(), $this->timedOut);
        }
        return ['type' => 'result', 'res' => $res, 'keys' => ColumnNames::deduplicate(self::fieldNames($res))];
    }

    public function fetchRow(mixed $stmt): ?array
    {
        if (($stmt['type'] ?? '') === 'cursor') {
            if ($this->buffer === null) {
                $res = @pg_query($this->conn, 'FETCH FORWARD ' . self::BATCH . ' FROM ' . $stmt['name']);
                if ($res === false) {
                    throw new DbQueryException($this->connError(), $this->timedOut);
                }
                // Rows are fetched numerically and named from the field list:
                // pg_fetch_all() builds assoc rows from raw column names, so a
                // repeated alias would collapse (see ColumnNames).
                $keys = ColumnNames::deduplicate(self::fieldNames($res));
                $rows = [];
                while (($raw = @pg_fetch_row($res)) !== false) {
                    $rows[] = count($keys) === count($raw) ? array_combine($keys, $raw) : $raw;
                }
                @pg_free_result($res);
                $this->buffer = $rows; // [] when the cursor is empty/exhausted
            }
            if ($this->buffer === null || $this->buffer === []) {
                return null;
            }
            $row = array_shift($this->buffer);
            if ($this->buffer === []) {
                $this->buffer = null; // refill on the next call
            }
            return $row;
        }
        if (($stmt['type'] ?? '') === 'result') {
            $raw = @pg_fetch_row($stmt['res']);
            if (!is_array($raw)) {
                return null;
            }
            $keys = $stmt['keys'] ?? null;
            return is_array($keys) && count($keys) === count($raw)
                ? array_combine($keys, $raw)
                : $raw;
        }
        return null;
    }

    public function nextResult(mixed $stmt): bool
    {
        // Single-statement protocol usage (cursor or one result); a batch
        // never reaches this driver — scripts mode is SQL Server only.
        return false;
    }

    /**
     * Raw column names of a result, in order (duplicates included).
     * @param resource $res
     * @return list<string>
     */
    private static function fieldNames($res): array
    {
        $names = [];
        $count = (int)@pg_num_fields($res);
        for ($i = 0; $i < $count; $i++) {
            $names[] = (string)@pg_field_name($res, $i);
        }
        return $names;
    }

    public function free(mixed $stmt): void
    {
        if (($stmt['type'] ?? '') === 'cursor' && is_string($this->cursor)) {
            @pg_query($this->conn, 'CLOSE ' . $this->cursor);
            @pg_query($this->conn, 'COMMIT');
            $this->cursor = null;
        }
        $this->buffer = null;
        if (($stmt['type'] ?? '') === 'result' && $stmt['res']) {
            @pg_free_result($stmt['res']);
        }
        if ($this->result !== null) {
            @pg_free_result($this->result);
            $this->result = null;
        }
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            @pg_query($this->conn, 'ROLLBACK');
            @pg_close($this->conn);
            $this->conn = null;
        }
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** ------------------------------------------------------------------ */

    private function quote(string $v): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
    }

    private function applySessionTimeout(): void
    {
        if (@pg_query($this->conn, 'SET statement_timeout = ' . ($this->timeout * 1000)) === false) {
            // statement_timeout unsupported in this variant; queries still work.
        }
        @pg_query($this->conn, "SET timezone TO 'Asia/Tehran'");
    }

    private function connError(): string
    {
        $msg = '';
        if ($this->conn !== null) {
            try {
                $msg = trim((string)@pg_last_error($this->conn));
            } catch (\Throwable) {
                $msg = '';
            }
        }
        if ($msg === '') {
            $err = error_get_last();
            if ($err !== null && !empty($err['message'])) {
                $msg = preg_replace('/^pg_[a-z0-9_]+\(\):\s*/i', '', (string)$err['message']);
            }
        }
        if ($msg === '') {
            $msg = 'unknown PostgreSQL error';
        }
        $this->lastError = $msg;
        $this->timedOut = $this->isTimeoutText($msg);
        return $msg;
    }

    private function isTimeoutText(string $msg): bool
    {
        $m = strtolower($msg);
        return str_contains($m, '57014')
            || str_contains($m, 'statement timeout')
            || str_contains($m, 'timeout expired')
            || str_contains($m, 'timed out')
            || str_contains($m, 'canceled');
    }
}
