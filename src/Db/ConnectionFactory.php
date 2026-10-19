<?php
declare(strict_types=1);
namespace Db;

use Services\Encryption;

/**
 * Builds a connected driver from a raw params array (tests, admin test-run)
 * or from a `connections` table row (worker; decrypts the stored password).
 */
final class ConnectionFactory
{
    /** @var array<int,string> */
    private const TYPES = ['sqlserver', 'pgsql', 'mysql'];

    /**
     * @param array{db_type:string,host:string,port?:int|null,db:string,
     *              user:string,password:string,timeout?:int,
     *              trust_server_certificate?:bool,encrypt?:bool,
     *              login_timeout?:int} $p
     */
    public static function fromParams(array $p): DriverInterface
    {
        $p = self::normalize($p);
        $driver = match ($p['db_type']) {
            'sqlserver' => new SqlServerDriver(),
            'pgsql' => new PgSqlDriver(),
            'mysql' => new MySqlDriver(),
        };
        $driver->connect($p);
        return $driver;
    }

    /**
     * @param array<string,mixed> $row a row from the connections table
     */
    public static function fromConnectionRow(array $row, ?int $timeoutSeconds = null): DriverInterface
    {
        try {
            $password = Encryption::decrypt((string)$row['password_encrypted']);
        } catch (\RuntimeException $e) {
            throw new DbConnectException('Connection #' . (string)($row['id'] ?? '?') . ': ' . $e->getMessage());
        }
        return self::fromParams([
            'db_type' => (string)$row['db_type'],
            'host' => (string)$row['host'],
            'port' => $row['port'] !== null ? (int)$row['port'] : null,
            'db' => (string)$row['db_name'],
            'user' => (string)$row['username'],
            'password' => $password,
            'timeout' => $timeoutSeconds ?? (int)($row['timeout_seconds'] ?? 30),
        ]);
    }

    private static function normalize(array $p): array
    {
        $type = strtolower(trim((string)($p['db_type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new DbConnectException("Unsupported db_type '$type' (sqlserver|pgsql|mysql expected).");
        }
        foreach (['host', 'db', 'user'] as $k) {
            if (!isset($p[$k]) || trim((string)$p[$k]) === '') {
                throw new DbConnectException("Connection parameter '$k' is missing.");
            }
        }
        return [
            'db_type' => $type,
            'host' => trim((string)$p['host']),
            'port' => ($p['port'] ?? null) !== null ? (int)$p['port'] : null,
            'db' => (string)$p['db'],
            'user' => (string)$p['user'],
            'password' => (string)($p['password'] ?? ''),
            'timeout' => max(1, (int)($p['timeout'] ?? 30)),
            // SQL Server transport settings (other drivers take theirs from
            // their own DSN/conn string): resolved once here so the admin
            // test-run and the worker always use the same policy.
            'trust_server_certificate' => (bool)($p['trust_server_certificate']
                ?? \Core\Config::get('connections.sqlsrv_trust_server_certificate', false)),
            'encrypt' => (bool)($p['encrypt']
                ?? \Core\Config::get('connections.sqlsrv_encrypt', true)),
            'login_timeout' => max(1, (int)($p['login_timeout']
                ?? \Core\Config::get('connections.sqlsrv_login_timeout', 15))),
        ];
    }
}
