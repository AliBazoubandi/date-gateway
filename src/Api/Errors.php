<?php
declare(strict_types=1);
namespace Api;

use Core\Str;

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

    /**
     * Refuse a result-file delivery while keeping the CLIENT's downloaded file
     * intact — the refused download carries a 410 status and error headers,
     * then the transfer dies before the first body byte and NO response body
     * is ever written.
     *
     * Why not a normal JSON error body: curl -o result.json saves whatever
     * the response delivers — a 410 JSON body included — and exits 0, so an
     * accidental re-run of the download command after the result expired
     * would replace the good file the client had downloaded in the live
     * window with the error text and claim success while doing it. Delivered
     * bytes are the only bytes a client would write over its local file, so
     * there are none: Content-Length declares the JSON this refusal WOULD
     * have carried, nothing is echoed, and the connection ends short of that
     * declaration (curl answers `end of response with N bytes missing`,
     * exit 18; the -o output file is left untouched). Status-only readers
     * (headers still arrive first) keep their 410; the reason travels in
     * X-Gateway-Error / X-Gateway-Message instead of a body. Verifying curl
     * -o survival against an expired link is asserted in tests\api_smoke.php.
     */
    public static function refusedDelivery(string $code, string $message): never
    {
        // Header values must stay single-line: strip CR/LF from anything
        // future callers may parameterise with less care than these two.
        $clean = static function (string $value): string {
            return trim(str_replace(["\r", "\n"], ' ', $value));
        };
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Gateway-Error: ' . $clean($code));
        header('X-Gateway-Message: ' . $clean($message));
        // The body that would have been JSON — named, never delivered. NO
        // echo below: the first written byte would open the client's own
        // output file and reintroduce exactly the loss this refusal exists
        // to prevent.
        $planned = Str::json(['error' => ['code' => $clean($code), 'message' => $clean($message)]]);
        header('Content-Length: ' . strlen($planned));
        exit;
    }

    public static function rateLimited(int $retryAfterSeconds): void
    {
        self::emit(429, 'rate_limited', 'This connection is busy. Retry shortly.', [
            'Retry-After' => (string)$retryAfterSeconds,
        ]);
    }
}
