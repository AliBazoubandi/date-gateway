<?php
declare(strict_types=1);
namespace Db;

/**
 * MySQL driver for TARGET databases via native mysqli (unbuffered/streaming).
 * (The metadata DB has its own wrapper: Core\Db.)
 */
final class MySqlDriver implements DriverInterface
{
    /** @var \mysqli|null */
    private $m = null;
    /** @var resource|\mysqli_result|null */
    private $result = null;
    private int $timeout = 30;
    private string $lastError = '';
    private bool $timedOut = false;

    public function connect(array $o): void
    {
        if (!class_exists(\mysqli::class)) {
            throw new DbConnectException('The mysqli extension is not enabled in php.ini.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $m = mysqli_init();
        try {
            $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
            // Socket read timeout: guards the fetch phase on slow big results.
            $m->options(MYSQLI_OPT_READ_TIMEOUT, max(60, $o['timeout'] + 30));
            @$m->real_connect(
                (string)$o['host'],
                (string)$o['user'],
                (string)$o['password'],
                (string)$o['db'],
                (int)($o['port'] ?? 0) > 0 ? (int)$o['port'] : 3306
            );
        } catch (\mysqli_sql_exception $e) {
            throw new DbConnectException('MySQL connect failed: ' . $e->getMessage());
        }
        try {
            $m->set_charset('utf8mb4');
        } catch (\mysqli_sql_exception $e) {
            throw new DbConnectException('MySQL charset error: ' . $e->getMessage());
        }
        // Tehran everywhere (best effort; keep going if the server refuses).
        try {
            $m->query("SET time_zone = '+03:30'");
        } catch (\mysqli_sql_exception) {
        }
        $this->m = $m;
        $this->lastError = '';
        $this->timedOut = false;
    }

    public function setQueryTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
        if ($this->m === null) {
            return;
        }
        // MariaDB uses max_statement_time (seconds), MySQL 5.7+ uses
        // max_execution_time (milliseconds, SELECT only). Try both.
        try {
            $this->m->query('SET SESSION max_statement_time = ' . $this->timeout);
            return;
        } catch (\mysqli_sql_exception) {
        }
        try {
            $this->m->query('SET SESSION max_execution_time = ' . ($this->timeout * 1000));
        } catch (\mysqli_sql_exception) {
        }
    }

    public function query(string $sql): mixed
    {
        try {
            $ok = $this->m->real_query($sql);
        } catch (\mysqli_sql_exception $e) {
            throw $this->queryException($e->getMessage(), (int)$this->m->errno);
        }
        if ($ok === false) {
            throw $this->queryException($this->m->error, (int)$this->m->errno);
        }
        // Unbuffered: rows stream from the wire one by one.
        $res = $this->m->use_result();
        if ($res === false) {
            if ($this->m->field_count === 0) {
                return null; // INSERT/UPDATE/SET ... no result set
            }
            $res = $this->m->store_result(); // rare fallback
        }
        if ($res === false) {
            throw $this->queryException($this->m->error, (int)$this->m->errno);
        }
        $this->result = $res;
        return $res;
    }

    public function fetchRow(mixed $stmt): ?array
    {
        try {
            $row = $stmt->fetch_assoc();
        } catch (\mysqli_sql_exception $e) {
            // Unbuffered results surface errors (incl. max_statement_time
            // cancellations) here during the fetch phase.
            throw $this->queryException($e->getMessage(), (int)$this->m?->errno);
        }
        return is_array($row) ? $row : null;
    }

    public function free(mixed $stmt): void
    {
        if (is_object($stmt) && method_exists($stmt, 'free')) {
            // Discards the remaining unbuffered rows; safe mid-stream.
            @$stmt->free();
        }
        $this->result = null;
    }

    public function close(): void
    {
        if ($this->m !== null) {
            @$this->m->close();
            $this->m = null;
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

    private function queryException(string $message, int $errno): DbQueryException
    {
        $msg = '[' . $errno . '] ' . trim($message);
        $this->lastError = $msg;
        $this->timedOut = in_array($errno, [3024, 1969, 1317], true)
            || str_contains(strtolower($msg), 'max_statement_time')
            || str_contains(strtolower($msg), 'maximum statement execution time')
            || str_contains(strtolower($msg), 'timed out');
        return new DbQueryException($msg, $this->timedOut);
    }
}
