<?php
declare(strict_types=1);
namespace Services;

use Core\Config;
use Core\Db;
use Core\Str;
use Core\Time;

/**
 * Sends webhook_deliveries rows that the worker enqueued when an execution
 * became ready. One delivery per attached call; the receiver is the URL the
 * admins set on the client record.
 *
 * Contract:
 *  - Payload (exact bytes are what gets signed). For a ready result:
 *      {"call_id":..., "request_code":..., "status":"ready", "link":...,
 *       "expires_at":"...+03:30", "row_count":N}
 *    For a failed run the worker enqueues the delivery the same way and the
 *    payload follows the execution status:
 *      {"call_id":..., "request_code":..., "status":"failed", "link":null,
 *       "error":{"code":"timeout"|"execution_failed"|..., "message":"..."}}
 *    `error` carries only the sanitized code and fixed wording
 *    (Services\ExecutionError) - never the driver text stored in
 *    executions.error_message.
 *  - Headers: X-Gateway-Signature: sha256=<HMAC-SHA256 of
 *    "<unix timestamp>.<raw body>" with the client's webhook_secret>,
 *    X-Gateway-Timestamp: <unix timestamp — receivers must refuse timestamps
 *    older than ~5 minutes (replay protection)>, X-Gateway-Call: <call_id>,
 *    X-Gateway-Attempt: <n>.
 *  - Any 2xx acknowledges. Everything else (transport error or 4xx/5xx) is a
 *    failed attempt: retried up to config webhook.max_attempts times with the
 *    configured backoff, then the row goes to state='exhausted'.
 *  - Every attempt is logged (webhook_sent / webhook_failed).
 *  - The receiver URL is re-checked before every send by Services\WebhookUrl
 *    (scheme, optional host allowlist, and no loopback / link-local / reserved
 *    address; private ranges only while webhook.allow_private_hosts is on). A
 *    URL whose POLICY fails never reaches curl: the delivery goes to
 *    'exhausted' with the reason in last_error. A name that does not resolve
 *    right now is treated as transient instead - the attempt is recorded and
 *    the next retry is scheduled with the normal backoff, so a DNS hiccup does
 *    not silently lose the client's notification. An accepted address is pinned
 *    for the send with CURLOPT_RESOLVE, redirects are off, and only http(s) can
 *    be spoken.
 *
 * Deliveries of clients that cannot receive anything (inactive, or empty
 * webhook_url/webhook_secret) are NOT selected at all - they stay pending and
 * invisible until an admin fixes the client, without consuming the per-pass
 * LIMIT. Rows are still skipped in place (state stays 'pending', no attempt is
 * burned) while the attached execution has not settled (scheduled/running). A
 * result that EXPIRED before it could be delivered is exhausted immediately
 * (its link would 410 anyway).
 *
 * Only one process dispatches at a time: cli/worker.php and cli/webhooks.php
 * both hold the 'gw_cli_webhooks' metadata-DB named lock around this call.
 */
final class WebhookDispatcher
{
    /** Bytes of receiver response we are willing to read; the body is unused. */
    private const MAX_RESPONSE_BYTES = 65536;

    /** @return array{sent:int,failed:int,exhausted:int,skipped:int} */
    public static function deliverDue(?int $maxPerPass = null): array
    {
        $db = Db::instance();
        $limit = $maxPerPass ?? max(1, (int)Config::get('webhook.max_per_pass', 10));
        // Only deliveries that can actually be sent are selected: an inactive
        // client (or one with no webhook_url/webhook_secret) stays pending
        // forever, and would otherwise eat the whole per-pass LIMIT.
        $due = $db->rows(
            "SELECT d.id, d.call_id, d.attempt
             FROM webhook_deliveries d
             JOIN calls c ON c.id = d.call_id
             JOIN clients cl ON cl.id = c.client_id
             WHERE d.state = 'pending' AND (d.next_retry_at IS NULL OR d.next_retry_at <= NOW())
               AND cl.is_active = 1 AND cl.webhook_url <> '' AND cl.webhook_secret <> ''
             ORDER BY d.id
             LIMIT " . $limit
        );
        $stats = ['sent' => 0, 'failed' => 0, 'exhausted' => 0, 'skipped' => 0];
        foreach ($due as $delivery) {
            $stats[self::deliverOne($delivery)]++;
        }
        return $stats;
    }

    /** @param array<string,mixed> $delivery row from webhook_deliveries */
    private static function deliverOne(array $delivery): string
    {
        $db = Db::instance();
        $deliveryId = (int)$delivery['id'];
        $callId = (string)$delivery['call_id'];
        $attempt = (int)$delivery['attempt'];

        $call = $db->one(
            'SELECT c.id, c.client_id, c.request_id, c.execution_id,
                    cl.is_active, cl.webhook_url, cl.webhook_secret
             FROM calls c JOIN clients cl ON cl.id = c.client_id
             WHERE c.id = ?',
            [$callId]
        );
        $url = trim((string)($call['webhook_url'] ?? ''));
        $secret = (string)($call['webhook_secret'] ?? '');
        if ($call === null || (int)$call['is_active'] !== 1 || $url === '' || $secret === '') {
            return 'skipped'; // nothing configured to send to; retry when an admin sets it up
        }

        // Checked again on every send, not only when the client was saved: a
        // stored URL can predate the policy (or be edited straight in the DB),
        // and curl must never be handed an address the gate blocks. A URL the
        // POLICY refuses is exhausted rather than left pending forever — the
        // reason lands in last_error and the logs for the admin to fix it. A
        // name that merely does not resolve right now is a transient DNS
        // condition, so that attempt is retried with the normal backoff instead
        // of throwing the delivery away on the first DNS hiccup.
        $gate = WebhookUrl::check($url);
        if ($gate['error'] !== '') {
            if ($gate['retryable']) {
                return self::recordFailure($call, $callId, $deliveryId, $attempt, 0,
                    'receiver did not resolve: ' . $gate['error'], null, null, 'delivery attempt');
            }
            $db->execute(
                "UPDATE webhook_deliveries
                 SET state = 'exhausted', status_code = NULL, next_retry_at = NULL, last_error = ?
                 WHERE id = ? AND state = 'pending'",
                [Str::truncate('blocked: ' . $gate['error'], 500), $deliveryId]
            );
            Logger::event('webhook_failed', (int)$call['client_id'], (int)$call['request_id'], $callId,
                $call['execution_id'] === null ? null : (int)$call['execution_id'],
                null, null, null, 'webhook url blocked: ' . $gate['error']);
            return 'exhausted';
        }
        $resolve = $gate['resolve'];

        $exec = $call['execution_id'] !== null
            ? $db->one('SELECT * FROM executions WHERE id = ?', [$call['execution_id']])
            : null;
        if ($exec === null) {
            return 'skipped';
        }
        $status = (string)$exec['status'];
        $requestCode = (string)$db->value('SELECT code FROM requests WHERE id = ?', [$exec['request_id']]);

        if ($status === 'failed') {
            // The run never produced a result: notify with the sanitized reason
            // and no link. Nothing about this payload is retryable for the
            // client, but the receiver still has to be told.
            $payload = Str::json([
                'call_id' => $callId,
                'request_code' => $requestCode,
                'status' => 'failed',
                'link' => null,
                'error' => ExecutionError::forExecution($exec),
            ]);
        } else {
            if ($status === 'expired' || (!empty($exec['expires_at']) && $exec['expires_at'] <= Time::now())) {
                // The link would 410 by the time the receiver uses it; no point retrying.
                $db->execute(
                    "UPDATE webhook_deliveries SET state = 'exhausted', next_retry_at = NULL, last_error = ?
                     WHERE id = ? AND state = 'pending'",
                    ['result expired before it could be delivered', $deliveryId]
                );
                Logger::event('webhook_failed', (int)$call['client_id'], (int)$exec['request_id'], $callId,
                    (int)$exec['id'], null, null, (int)($exec['row_count'] ?? 0),
                    'delivery abandoned: result expired before delivery');
                return 'exhausted';
            }
            if ($status !== 'ready') {
                return 'skipped'; // still scheduled/running - deliver when it is settled
            }
            $payload = Str::json([
                'call_id' => $callId,
                'request_code' => $requestCode,
                'status' => 'ready',
                'link' => self::callLink($callId), // null when base_url is unconfigured
                'expires_at' => Time::iso((string)$exec['expires_at']),
                'row_count' => (int)($exec['row_count'] ?? 0),
            ]);
        }
        $timestamp = (string)time();
        // Sign over "<unix timestamp>.<raw body>" so a captured request
        // cannot be replayed later; receivers must refuse timestamps older
        // than a few minutes (see README, Webhooks).
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $t0 = microtime(true);
        [$httpCode, $transportError] = self::post($url, $payload, $signature, $callId, $attempt + 1, $timestamp, $resolve);
        $durationMs = (int)round((microtime(true) - $t0) * 1000);

        if ($httpCode >= 200 && $httpCode < 300) {
            $db->execute(
                "UPDATE webhook_deliveries
                 SET state = 'sent', status_code = ?, sent_at = ?, next_retry_at = NULL, last_error = NULL
                 WHERE id = ? AND state = 'pending'",
                [$httpCode, Time::now(), $deliveryId]
            );
            Logger::event('webhook_sent', (int)$call['client_id'], (int)$exec['request_id'], $callId,
                (int)$exec['id'], null, $durationMs, (int)($exec['row_count'] ?? 0),
                'delivered (HTTP ' . $httpCode . ', send #' . ($attempt + 1) . ')');
            return 'sent';
        }

        $detail = $transportError !== ''
            ? $transportError
            : 'HTTP ' . $httpCode;
        return self::recordFailure($call, $callId, $deliveryId, $attempt, $httpCode, $detail,
            $durationMs, (int)($exec['row_count'] ?? 0));
    }

    /**
     * One failed attempt: schedule the next try with the configured backoff, or
     * exhaust the row once webhook.max_attempts is used up. Shared by the
     * transport/HTTP failure path and the retryable URL-gate path (DNS), so the
     * two cannot drift apart.
     *
     * @param array<string,mixed> $call the calls row (client_id, request_id,
     *        execution_id) the delivery belongs to
     * @param string $label what failed, for the log line ('send', 'delivery attempt')
     * @return string 'failed' (retry scheduled) or 'exhausted'
     */
    private static function recordFailure(
        array $call,
        string $callId,
        int $deliveryId,
        int $attempt,
        int $httpCode,
        string $detail,
        ?int $durationMs,
        ?int $rowCount,
        string $label = 'send'
    ): string {
        $db = Db::instance();
        $execId = $call['execution_id'] === null ? null : (int)$call['execution_id'];
        $maxAttempts = max(1, (int)Config::get('webhook.max_attempts', 5));
        $backoff = (array)Config::get('webhook.backoff_minutes', [1, 2, 5, 10, 30]);
        $failedCount = $attempt + 1;

        if ($failedCount > $maxAttempts) {
            $db->execute(
                "UPDATE webhook_deliveries
                 SET state = 'exhausted', status_code = ?, next_retry_at = NULL, last_error = ?
                 WHERE id = ? AND state = 'pending'",
                [$httpCode > 0 ? $httpCode : null, Str::truncate('gave up: ' . $detail, 500), $deliveryId]
            );
            Logger::event('webhook_failed', (int)$call['client_id'], (int)$call['request_id'], $callId,
                $execId, null, $durationMs, $rowCount,
                'delivery gave up after ' . $failedCount . ' ' . $label . 's: ' . Str::truncate($detail, 300));
            return 'exhausted';
        }

        $step = (int)($backoff[min($failedCount - 1, count($backoff) - 1)] ?? 5);
        $nextRetry = Time::mysql(Time::addMinutes(Time::nowDt(), max(1, $step)));
        $db->execute(
            "UPDATE webhook_deliveries
             SET attempt = ?, status_code = ?, next_retry_at = ?, last_error = ?
             WHERE id = ? AND state = 'pending'",
            [$failedCount, $httpCode > 0 ? $httpCode : null, $nextRetry, Str::truncate($detail, 500), $deliveryId]
        );
        Logger::event('webhook_failed', (int)$call['client_id'], (int)$call['request_id'], $callId,
            $execId, null, $durationMs, $rowCount,
            $label . ' #' . $failedCount . ' failed (' . $detail . '); retry in ' . max(1, $step) . ' min');
        return 'failed';
    }

    /**
     * Per-call download link for the payload. Best-effort: when the link
     * cannot be built (e.g. GATEWAY_BASE_URL unset), delivery must still
     * happen with link=null — cli/check.php flags the configuration.
     */
    private static function callLink(string $callId): ?string
    {
        try {
            return \Api\Links::forCall($callId);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * POST the payload. @return array{0:int,1:string} HTTP code (0 = transport
     * failure) and a short transport-error string ('' when the server answered).
     *
     * @param list<string> $resolve CURLOPT_RESOLVE entries pinning the validated
     *        host to the address WebhookUrl checked, so a rebinding DNS answer
     *        cannot point this send somewhere the gate refused.
     */
    private static function post(
        string $url,
        string $payload,
        string $signature,
        string $callId,
        int $sendNo,
        string $timestamp,
        array $resolve = []
    ): array {
        if (!function_exists('curl_init')) {
            return [0, 'the curl extension is not enabled in php.ini'];
        }
        $timeout = max(1, (int)Config::get('webhook.timeout_seconds', 10));
        $protocols = CURLPROTO_HTTPS;
        if ((bool)Config::get('webhook.allow_http', false)) {
            $protocols |= CURLPROTO_HTTP;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, 'curl init failed'];
        }
        // The response body is never used - only the status code. Reading it
        // through a callback that stops after a small budget keeps a chatty or
        // hostile receiver from making this process buffer whatever it sends.
        $read = 0;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Gateway-Signature: ' . $signature,
                'X-Gateway-Timestamp: ' . $timestamp,
                'X-Gateway-Call: ' . $callId,
                'X-Gateway-Attempt: ' . (string)$sendNo,
            ],
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => $protocols,
            // Redirects stay off: following one would re-resolve and re-scheme
            // outside the checks above.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function (string $chunk) use (&$read): int {
                $read += strlen($chunk);
                return $read <= self::MAX_RESPONSE_BYTES ? strlen($chunk) : 0;
            },
        ]);
        if ($resolve !== []) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false) {
            $err = (string)curl_error($ch);
            curl_close($ch);
            // Stopping the body read makes libcurl report a write error; a 2xx
            // answer is still an acknowledged delivery, so do not retry it.
            return $code >= 200 && $code < 300
                ? [$code, '']
                : [0, 'curl: ' . Str::truncate($err, 200)];
        }
        curl_close($ch);
        return [$code, ''];
    }
}
