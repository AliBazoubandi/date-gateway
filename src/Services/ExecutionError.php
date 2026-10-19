<?php
declare(strict_types=1);
namespace Services;

/**
 * The public, sanitized description of a failed execution.
 *
 * `executions.error_message` holds the raw driver text (SQL fragments, host,
 * port, sometimes a username) for the admin logs, and must never reach a
 * client. These codes plus their fixed wording are what the status endpoint
 * and the webhook payload expose.
 */
final class ExecutionError
{
    public const TIMEOUT = 'timeout';
    public const FAILED = 'execution_failed';
    public const REJECTED = 'query_rejected';
    public const TOO_LARGE = 'result_too_large';
    public const STORAGE_LOW = 'storage_low';

    /** @var array<string,string> code => client-facing wording */
    private const MESSAGES = [
        self::TIMEOUT => 'The query did not finish within its timeout and was stopped before it produced a result.',
        self::FAILED => 'The query failed on the gateway. Details were recorded in the gateway logs.',
        self::REJECTED => 'The stored query was rejected by the gateway read-only policy. Contact the gateway administrator.',
        self::TOO_LARGE => 'The result exceeded the gateway size limit. Narrow the query or reduce max_rows.',
        self::STORAGE_LOW => 'The gateway does not have enough free disk space to store results right now. Try again later.',
    ];

    /** Map a stored executions.error_code to a public code (old rows have none). */
    public static function code(?string $stored): string
    {
        $code = trim((string)$stored);
        return isset(self::MESSAGES[$code]) ? $code : self::FAILED;
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[self::code($code)];
    }

    /**
     * The error object for an execution row: null unless the run failed.
     *
     * @param array<string,mixed> $exec
     * @return array{code:string,message:string}|null
     */
    public static function forExecution(array $exec): ?array
    {
        if ((string)($exec['status'] ?? '') !== 'failed') {
            return null;
        }
        $code = self::code($exec['error_code'] ?? null);
        return ['code' => $code, 'message' => self::message($code)];
    }
}
