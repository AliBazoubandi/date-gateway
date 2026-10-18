<?php
declare(strict_types=1);
namespace Api;

use Api\Errors;
use Core\Config;
use Core\Db;
use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Logger;

/**
 * GET /api/v1/results/{token} — chunked, time-limited JSON delivery.
 * Needs: the raw token + the client's Bearer key + a call by THAT client
 * attached to the execution. 410 when expired, 404 when unknown/foreign.
 * The streamed body carries Content-Disposition so browsers and curl -O
 * save it under a stable name. The same streaming path serves instant
 * submissions when they are called with ?download=1.
 */
final class Download
{
    public static function handle(string $token): void
    {
        $client = ApiAuth::requireClient();
        $db = Db::instance();
        $ip = client_ip();

        $exec = $db->one('SELECT * FROM executions WHERE download_token_hash = ?', [hash('sha256', $token)]);
        $viaCallId = null;
        if ($exec !== null) {
            // The worker-issued execution token: caller must own a call on it.
            $owned = (int)$db->value(
                'SELECT COUNT(*) FROM calls WHERE client_id = ? AND execution_id = ?',
                [(int)$client['id'], $exec['id']]
            ) > 0;
            if (!$owned) {
                Logger::event('denied', (int)$client['id'], null, null, (int)$exec['id'], $ip, null, null,
                    'download attempt without owning call');
                Errors::notFound('Unknown token.');
            }
            $viaCallId = (string)$db->value('SELECT id FROM calls WHERE client_id = ? AND execution_id = ? LIMIT 1',
                [(int)$client['id'], $exec['id']]);
        } else {
            // A per-call minted token (rule 2 attach): resolves to that call.
            $viaCall = $db->one('SELECT id, client_id, execution_id FROM calls WHERE token_hash = ?', [hash('sha256', $token)]);
            if ($viaCall === null || (int)$viaCall['client_id'] !== (int)$client['id']) {
                Errors::notFound('Unknown token.');
            }
            $viaCallId = (string)$viaCall['id'];
            $exec = $viaCall['execution_id'] !== null
                ? $db->one('SELECT * FROM executions WHERE id = ?', [$viaCall['execution_id']])
                : null;
            if ($exec === null) {
                Errors::notFound('Unknown token.');
            }
        }

        self::streamResult($exec, $viaCallId, $ip, 'result delivered');
    }

    /**
     * Shared result-file delivery for the GET download endpoint and for
     * instant submissions called with ?download=1. Guards status/expiry
     * (410 when past TTL) and streams the JSON envelope file chunk by chunk.
     *
     * @param array<string,mixed> $exec a full executions row
     */
    public static function streamResult(array $exec, ?string $callId, string $ip, string $note): never
    {
        $db = Db::instance();
        $clientId = (int)$db->value('SELECT client_id FROM calls WHERE id = ? LIMIT 1', [$callId ?? '0']);
        $status = (string)$exec['status'];

        if ($status === 'expired') {
            Logger::event('expired', $clientId, (int)$exec['request_id'], $callId, (int)$exec['id'], $ip,
                null, (int)($exec['row_count'] ?? 0), 'download after expiry');
            Errors::gone('This result has expired.');
        }
        if ($status !== 'ready') {
            Errors::notFound('Unknown token.');
        }
        if (!empty($exec['expires_at']) && $exec['expires_at'] <= Time::now()) {
            $db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$exec['id']]);
            Logger::event('expired', $clientId, (int)$exec['request_id'], $callId,
                (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), 'expired during download');
            Errors::gone('This result has expired.');
        }

        $absolute = Filesystem::absoluteFromRelative((string)$exec['result_path']);
        if ($exec['result_path'] === null || !is_file($absolute)) {
            // Ready but the file vanished (manual cleanup?): treat as gone.
            $db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$exec['id']]);
            Errors::gone('This result is no longer available.');
        }

        $code = (string)$db->value('SELECT code FROM requests WHERE id = ?', [$exec['request_id']]);
        $filename = 'result-' . (Str::isCode($code) ? $code : 'request') . '-' . (string)$exec['id'] . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)filesize($absolute));

        Logger::event('downloaded', $clientId, (int)$exec['request_id'], $callId,
            (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), $note);

        $chunk = max(1024, (int)Config::get('downloads.chunk_size', 65536));
        $fh = fopen($absolute, 'rb');
        if ($fh === false) {
            Errors::emit(500, 'internal_error', 'Cannot open result file.');
            exit; // explicit: emit() exits, keep the streaming path unreachable
        }
        while (!feof($fh)) {
            $buf = fread($fh, $chunk);
            if ($buf === false || $buf === '') {
                break;
            }
            echo $buf;
            flush();
        }
        fclose($fh);
        exit;
    }
}
