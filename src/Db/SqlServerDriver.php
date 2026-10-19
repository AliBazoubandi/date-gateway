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
    /** @var list<string>|null deduplicated column names of the open statement */
    private ?array $columnKeys = null;

    public function connect(array $o): void
    {
        if (!function_exists('sqlsrv_connect')) {
            throw new DbConnectException(
                'The sqlsrv extension is not enabled in php.ini (extension=php_sqlsrv_*.dll).'
            );
        }
        $server = $o['host'] . (($o['port'] ?? 0) > 0 ? ',' . (int)$o['port'] : '');
        $conn = @sqlsrv_connect($server, self::connectionOptions($o));
        if ($conn === false) {
            throw new DbConnectException('SQL Server connect failed: ' . $this->collectErrors(null));
        }
        $this->conn = $conn;
        $this->lastError = '';
    }

    /**
     * The sqlsrv connection options for a parameter set, kept separate from
     * connect() so the security-relevant defaults are testable without a
     * server (and without the extension).
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    public static function connectionOptions(array $o): array
    {
        // Handshake cap: a query timeout of 300 s must not mean a worker slot
        // blocked for 300 s on a server that never answers.
        $cap = max(1, (int)($o['login_timeout'] ?? 15));
        $query = max(1, (int)($o['timeout'] ?? 30));
        return [
            'Database' => $o['db'],
            'UID' => $o['user'],
            'PWD' => $o['password'],
            'ReturnDatesAsStrings' => true, // DateTime objects must not leak into JSON rows
            // Required for non-ASCII data (e.g. Persian in VARCHAR columns): the
            // driver converts varchar data/column names through the CLIENT
            // codepage unless this is set, which corrupts them to '?' and
            // produces invalid UTF-8 that breaks JSON encoding downstream.
            'CharacterSet' => 'UTF-8',
            // Encrypted transport by default (connections.sqlsrv_encrypt), and
            // the certificate is validated by default too
            // (connections.sqlsrv_trust_server_certificate = false): an
            // unvalidated certificate means anyone on the path to the target
            // database can read and answer as if it were it. For an intranet
            // server whose self-signed certificate cannot be installed in the
            // Windows trust store, set GATEWAY_SQLSRV_TRUST_SERVER_CERT=1.
            'Encrypt' => (bool)($o['encrypt'] ?? true),
            'TrustServerCertificate' => (bool)($o['trust_server_certificate'] ?? false),
            'LoginTimeout' => min($cap, max(5, $query)),
        ];
    }

    public function setQueryTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    public function query(string $sql): mixed
    {
        $this->columnKeys = null;
        $stmt = @sqlsrv_query($this->conn, $sql, [], ['QueryTimeout' => $this->timeout]);
        if ($stmt === false) {
            $msg = $this->collectErrors(null);
            $this->lastError = $msg;
            throw new DbQueryException($msg, $this->isTimeoutText($msg));
        }
        $meta = @sqlsrv_field_metadata($stmt);
        if (is_array($meta)) {
            $names = [];
            foreach ($meta as $field) {
                $names[] = (string)($field['Name'] ?? '');
            }
            $this->columnKeys = ColumnNames::deduplicate($names);
        }
        return $stmt;
    }

    public function fetchRow(mixed $stmt): ?array
    {
        // Numeric, not assoc: a repeated column name would otherwise collapse
        // and the earlier value would vanish (see ColumnNames).
        $row = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        if ($row === false) {
            $msg = $this->collectErrors(null);
            $this->lastError = $msg;
            throw new DbQueryException($msg, $this->isTimeoutText($msg));
        }
        if (!is_array($row)) {
            return null;
        }
        $keys = $this->columnKeys;
        return ($keys === null || count($keys) !== count($row))
            ? $row // metadata unavailable: keep the row positionally
            : array_combine($keys, $row);
    }

    public function free(mixed $stmt): void
    {
        if ($stmt !== null) {
            @sqlsrv_free_stmt($stmt);
        }
        $this->columnKeys = null;
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
