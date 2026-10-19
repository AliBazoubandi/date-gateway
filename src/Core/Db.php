<?php
declare(strict_types=1);
namespace Core;

/**
 * Wrapper around mysqli for the metadata database (MySQL/MariaDB).
 *
 * Rule: every parameterized statement goes through here, so no caller ever
 * concatenates SQL strings. Literal, constant statements (SET time_zone etc.)
 * may use plain ->query().
 */
final class Db
{
    private static ?Db $instance = null;

    /** @var \mysqli|null */
    private $m = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $host = (string)Config::get('metadata_db.host', '127.0.0.1');
        $port = (int)Config::get('metadata_db.port', 3306);
        $user = (string)Config::get('metadata_db.user', 'root');
        $pass = (string)Config::get('metadata_db.password', '');
        $name = (string)Config::get('metadata_db.database', 'data_gateway');

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $m = mysqli_init();
        try {
            $m->real_connect($host, $user, $pass, $name, $port);
        } catch (\mysqli_sql_exception $e) {
            throw new DbException(
                'Metadata DB connect failed: ' . $e->getMessage() . ' (check config/config.php)',
                (int)$e->getCode(),
                $e
            );
        }

        try {
            $m->set_charset('utf8mb4');
        } catch (\mysqli_sql_exception $e) {
            throw new DbException('Metadata DB charset error: ' . $e->getMessage(), 0, $e);
        }

        // Tehran everywhere: store/return all timestamps in +03:30.
        try {
            $m->query("SET time_zone = '+03:30'");
        } catch (\mysqli_sql_exception $e) {
            throw new DbException('Metadata DB timezone error: ' . $e->getMessage(), 0, $e);
        }

        $this->m = $m;
    }

    /** @return \mysqli */
    public function conn()
    {
        if ($this->m === null) {
            throw new DbException('Metadata DB connection is closed.');
        }
        return $this->m;
    }

    public function insertId(): int
    {
        return (int)$this->conn()->insert_id;
    }

    /**
     * Affected rows of the LAST statement this connection ran.
     * CAUTION: prepared statements reset this to -1 once closed (see execute()).
     * Always prefer the return value of execute() / rows() over a second read.
     */
    public function affectedRows(): int
    {
        return (int)$this->conn()->affected_rows;
    }

    // ------------------------------------------------------------------------
    // Prepared-statement helpers
    // ------------------------------------------------------------------------

    /**
     * @return \mysqli_stmt
     */
    private function stmt(string $sql, array $params): \mysqli_stmt
    {
        $m = $this->conn();
        try {
            $st = $m->prepare($sql);
        } catch (\mysqli_sql_exception $e) {
            throw new DbException('SQL prepare failed: ' . $e->getMessage(), 0, $e);
        }
        if ($st === false) {
            throw new DbException('SQL prepare failed: ' . $m->error);
        }
        if ($params !== []) {
            $this->bind($st, $params);
        }
        return $st;
    }

    /** @param array<int,mixed> $params */
    private function bind(\mysqli_stmt $st, array $params): void
    {
        $types = '';
        $values = [];
        foreach ($params as $v) {
            if (is_int($v)) {
                $types .= 'i';
            } elseif (is_float($v)) {
                $types .= 'd';
            } elseif (is_bool($v)) {
                $types .= 'i';
                $v = $v ? 1 : 0;
            } else {
                $types .= 's'; // strings AND nulls
            }
            $values[] = $v;
        }
        if ($types === '') {
            return;
        }
        $refs = [];
        foreach ($values as $k => $val) {
            $refs[$k] = &$values[$k];
        }
        array_unshift($refs, $types);
        try {
            call_user_func_array([$st, 'bind_param'], $refs);
        } catch (\ArgumentCountError | \TypeError $e) {
            throw new DbException('SQL bind failed: ' . $e->getMessage());
        }
    }

    /**
     * Run a statement, return all rows (small result sets only: metadata data).
     * @return array<int,array<string,mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $st = $this->stmt($sql, $params);
        try {
            $st->execute();
            $res = $st->get_result();
            if ($res !== false) {
                $rows = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
            } else {
                $rows = $this->manualFetch($st);
            }
        } catch (\mysqli_sql_exception $e) {
            throw new DbException('SQL query failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $st->close();
        }
        return $rows;
    }

    /** First row or null. */
    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows === [] ? null : $rows[0];
    }

    /** First column of the first row (null when no row). */
    public function value(string $sql, array $params = [])
    {
        $row = $this->one($sql, $params);
        return $row === null ? null : array_values($row)[0];
    }

    /**
     * Run INSERT/UPDATE/DELETE (also small SELECTs pass through rows()/value()).
     * @return int affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $st = $this->stmt($sql, $params);
        try {
            $st->execute();
            $n = $this->affectedRows();
        } catch (\mysqli_sql_exception $e) {
            throw new DbException('SQL execute failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $st->close();
        }
        return $n;
    }

    /** Fetch rows without mysqlnd (no get_result) — manual binding fallback. */
    private function manualFetch(\mysqli_stmt $st): array
    {
        $meta = $st->result_metadata();
        if ($meta === false) {
            return [];
        }
        $fields = [];
        $refs = [];
        while ($f = $meta->fetch_field()) {
            $name = $f->name;
            $fields[] = $name;
            $refs[] = &$row[$name];
        }
        $meta->free();
        call_user_func_array([$st, 'bind_result'], $refs);
        $rows = [];
        while ($st->fetch()) {
            $out = [];
            foreach ($fields as $name) {
                $out[$name] = $row[$name];
            }
            $rows[] = $out;
        }
        unset($row);
        return $rows;
    }

    // ------------------------------------------------------------------------
    // Transactions and named locks
    // ------------------------------------------------------------------------

    public function begin(): void
    {
        $this->conn()->begin_transaction();
    }

    public function commit(): void
    {
        $this->conn()->commit();
    }

    public function rollback(): void
    {
        $this->conn()->rollback();
    }

    /**
     * Run $fn as one transaction: commit when it returns, roll everything back
     * when it throws and let the exception propagate. Multi-step writes must
     * not be left half applied - a scheduled execution with no call attached
     * runs a real query for nobody, and an execution marked ready with no
     * webhook row leaves the client waiting for a notification that will never
     * arrive. Do not nest (MySQL has no savepoints here) and do not mix in
     * DDL, which commits implicitly.
     *
     * @param callable(): mixed $fn
     */
    public function transaction(callable $fn): mixed
    {
        $this->begin();
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            try {
                $this->rollback();
            } catch (\Throwable) {
                // The connection is already gone: there is nothing left to undo.
            }
            throw $e;
        }
        $this->commit();
        return $result;
    }

    /**
     * GET_LOCK wrapper. Returns true when the lock was acquired.
     */
    public function lock(string $name, int $waitSeconds = 30): bool
    {
        $v = $this->value('SELECT GET_LOCK(?, ?)', [$name, $waitSeconds]);
        return (int)$v === 1;
    }

    public function unlock(string $name): void
    {
        $this->execute('SELECT RELEASE_LOCK(?)', [$name]);
    }

    public function ping(): bool
    {
        return $this->conn()->ping();
    }

    /** Escape % and _ for a LIKE pattern built from user input (use with ?). */
    public static function likeContains(string $s): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s) . '%';
    }
}
