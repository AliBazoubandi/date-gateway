<?php
declare(strict_types=1);
namespace Db;

/**
 * SQL Server driver via the native sqlsrv_* extension (no PDO).
 * Rows stream one by one (non-scrollable forward-only statements).
 */
final class SqlServerDriver implements DriverInterface
{
    /** @var resource|null */
    private $conn = null;
    private int $timeout = 30;
    private string $lastError = '';

    public function connect(array $o): void
    {
        if (!function_exists('sqlsrv_connect')) {
            throw new DbConnectException(
                'The sqlsrv extension is not enabled in php.ini (extension=php_sqlsrv_*.dll).'
            );
        }
        $server = $o['host'] . (($o['port'] ?? 0) > 0 ? ',' . (int)$o['port'] : '');
        $options = [
            'Database' => $o['db'],
            'UID' => $o['user'],
            'PWD' => $o['password'],
            'ReturnDatesAsStrings' => true, // DateTime objects must not leak into JSON rows
            // Required for non-ASCII data (e.g. Persian in VARCHAR columns): the
            // driver converts varchar data/column names through the CLIENT
            // codepage unless this is set, which corrupts them to '?' and
            // produces invalid UTF-8 that breaks JSON encoding downstream.
            'CharacterSet' => 'UTF-8',
            // Intranet servers with self-signed TLS; set
            // connections.sqlsrv_trust_server_certificate = false to enforce validation.
            'TrustServerCertificate' => (bool)($o['trust_server_certificate'] ?? true),
            'LoginTimeout' => max(10, (int)$o['timeout']),
        ];
        $conn = @sqlsrv_connect($server, $options);
        if ($conn === false) {
            throw new DbConnectException('SQL Server connect failed: ' . $this->collectErrors(null));
        }
        $this->conn = $conn;
        $this->lastError = '';
    }

    public function setQueryTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    public function query(string $sql): mixed
    {
        $stmt = @sqlsrv_query($this->conn, $sql, [], ['QueryTimeout' => $this->timeout]);
        if ($stmt === false) {
            $msg = $this->collectErrors(null);
            $this->lastError = $msg;
            throw new DbQueryException($msg, $this->isTimeoutText($msg));
        }
        return $stmt;
    }

    public function fetchRow(mixed $stmt): ?array
    {
        $row = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row === false) {
            $msg = $this->collectErrors(null);
            $this->lastError = $msg;
            throw new DbQueryException($msg, $this->isTimeoutText($msg));
        }
        return $row === null ? null : $row;
    }

    public function free(mixed $stmt): void
    {
        if ($stmt !== null) {
            @sqlsrv_free_stmt($stmt);
        }
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            @sqlsrv_close($this->conn);
            $this->conn = null;
        }
    }

    public function timedOut(): bool
    {
        return $this->isTimeoutText($this->lastError);
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    private function collectErrors($ignored = null): string
    {
        $errs = @sqlsrv_errors(SQLSRV_ERR_ERRORS);
        if (!is_array($errs) || $errs === []) {
            return 'unknown sqlsrv error';
        }
        $parts = [];
        foreach ($errs as $err) {
            $parts[] = '[' . ($err['SQLSTATE'] ?? '') . ' ' . ($err['code'] ?? '') . '] '
                . trim((string)($err['message'] ?? ''));
        }
        return implode(' | ', $parts);
    }

    private function isTimeoutText(string $msg): bool
    {
        $m = strtolower($msg);
        return str_contains($m, 'hyt00')
            || str_contains($m, 'imt00')
            || str_contains($m, 'timed out')
            || str_contains($m, 'timeout expired')
            || str_contains($m, 'execution timeout');
    }
}
