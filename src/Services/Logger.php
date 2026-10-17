<?php
declare(strict_types=1);
namespace Services;

use Core\Db;
use Core\Str;
use Core\Time;

/**
 * Append-only request log writer.
 */
final class Logger
{
    public static function event(
        string $event,
        ?int $clientId,
        ?int $requestId,
        ?string $callId,
        ?int $executionId,
        ?string $ip = null,
        ?int $durationMs = null,
        ?int $rowCount = null,
        string $message = ''
    ): void {
        try {
            Db::instance()->execute(
                'INSERT INTO request_logs (`timestamp`, client_id, request_id, call_id, execution_id, event, ip, duration_ms, row_count, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Time::now(),
                    $clientId ?? null,
                    $requestId ?? null,
                    $callId,
                    $executionId,
                    Str::sanitizeEvent($event),
                    $ip,
                    $durationMs,
                    $rowCount,
                    $message !== '' ? Str::truncate($message, 2000) : null,
                ]
            );
        } catch (\Throwable $e) {
            // The log must never break the data path; surface to error log only.
            error_log('request_logs insert failed: ' . $e->getMessage());
        }
    }
}
