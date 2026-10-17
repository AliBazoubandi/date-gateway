<?php
declare(strict_types=1);
namespace Api;

final class Errors
{
    /** @return never */
    public static function emit(int $httpStatus, string $code, string $message, array $headers = []): void
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        json_out(['error' => ['code' => $code, 'message' => $message]], $httpStatus);
    }

    public static function unauthorized(string $message = 'API key missing or invalid. Use "Authorization: Bearer <key>".'): void
    {
        self::emit(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message): void
    {
        self::emit(403, 'forbidden', $message);
    }

    public static function notFound(string $message): void
    {
        self::emit(404, 'not_found', $message);
    }

    public static function gone(string $message): void
    {
        self::emit(410, 'expired', $message);
    }

    public static function rateLimited(int $retryAfterSeconds): void
    {
        self::emit(429, 'rate_limited', 'This connection is busy. Retry shortly.', [
            'Retry-After' => (string)$retryAfterSeconds,
        ]);
    }
}
