<?php
declare(strict_types=1);
namespace Services;

use Db\ConnectionFactory;
use Db\DbConnectException;
use Db\DbQueryException;

/**
 * Runs a pre-approved query on a target connection, streaming rows one by one
 * (never buffering the whole result). Used by the admin test-run, the instant
 * executor and the delayed worker.
 *
 * @param array<string,mixed> $connectionRow a row from the connections table
 * @param callable(array<string,mixed>): void|null $onRow
 */
final class QueryRunner
{
    /**
     * @return array{ok:bool,row_count:int,truncated:bool,duration_ms:int,error:string,timed_out:bool,rejected:bool}
     */
    public static function run(
        array $connectionRow,
        string $sql,
        int $timeoutSeconds,
        int $maxRows,
        ?callable $onRow = null
    ): array {
        $t0 = microtime(true);
        $out = ['ok' => false, 'row_count' => 0, 'truncated' => false,
            'duration_ms' => 0, 'error' => '', 'timed_out' => false, 'rejected' => false];
        $driver = null;
        try {
            // Every execution path funnels through here, so this is the
            // enforcement point for the read-only guarantee (P1-6). The
            // dialect comes from the connection: the same bytes mean different
            // things on MySQL, PostgreSQL and SQL Server (see SqlGuard).
            SqlGuard::assertReadOnly($sql, (string)($connectionRow['db_type'] ?? ''));
            $driver = ConnectionFactory::fromConnectionRow($connectionRow, $timeoutSeconds);
            $driver->setQueryTimeout($timeoutSeconds);
            $stmt = $driver->query($sql);
            while (true) {
                $row = $driver->fetchRow($stmt);
                if ($row === null) {
                    break;
                }
                if ($out['row_count'] >= $maxRows) {
                    $out['truncated'] = true;
                    break;
                }
                $out['row_count']++;
                if ($onRow !== null) {
                    $onRow($row);
                }
            }
            $out['ok'] = true;
        } catch (ResultTooLargeException $e) {
            // Size guard, not a query failure: the caller must see it
            // distinctly (413 for instant, failed execution for delayed).
            throw $e;
        } catch (SqlRejectedException $e) {
            // Refused before reaching the target: callers report query_rejected
            // rather than a generic execution failure (P1-6).
            $out['rejected'] = true;
            $out['error'] = $e->getMessage();
        } catch (DbQueryException $e) {
            $out['error'] = $e->getMessage();
            $out['timed_out'] = $e->isTimeout();
        } catch (DbConnectException $e) {
            $out['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        } finally {
            if ($driver !== null) {
                try {
                    if (isset($stmt) && $stmt !== null) {
                        $driver->free($stmt);
                    }
                } catch (\Throwable) {
                }
                $driver->close();
            }
            $out['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
        }
        return $out;
    }
}
