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
 */
final class Download
{
    public static function handle(string $token): void
    {
        $client = ApiAuth::requireClient();
        $db = Db::instance();
        $ip = client_ip();

        $exec = $db->one('SELECT * FROM executions WHERE download_token_hash = ?', [hash('sha256', $token)]);
        $viaCall = null;
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
        } else {
            // A per-call minted token (rule 2 attach): resolves to that call.
            $viaCall = $db->one('SELECT id, client_id, execution_id FROM calls WHERE token_hash = ?', [hash('sha256', $token)]);
            if ($viaCall === null || (int)$viaCall['client_id'] !== (int)$client['id']) {
                Errors::notFound('Unknown token.');
            }
            $exec = $viaCall['execution_id'] !== null
                ? $db->one('SELECT * FROM executions WHERE id = ?', [$viaCall['execution_id']])
                : null;
            if ($exec === null) {
                Errors::notFound('Unknown token.');
            }
        }

        if ((string)$exec['status'] === 'expired' ||
            (in_array((string)$exec['status'], ['scheduled', 'running', 'failed'], true))) {
            if ((string)$exec['status'] === 'expired') {
                Logger::event('expired', (int)$client['id'], (int)$exec['request_id'], null, (int)$exec['id'], $ip,
                    null, (int)($exec['row_count'] ?? 0), 'download after expiry');
                Errors::gone('This result has expired.');
            }
            Errors::notFound('Unknown token.');
        }
        if (!empty($exec['expires_at']) && $exec['expires_at'] <= Time::now()) {
            $db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$exec['id']]);
            Logger::event('expired', (int)$client['id'], (int)$exec['request_id'], $viaCall !== null ? (string)$viaCall['id'] : null,
                (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), 'expired during download');
            Errors::gone('This result has expired.');
        }

        $absolute = Filesystem::absoluteFromRelative((string)$exec['result_path']);
        if ($exec['result_path'] === null || !is_file($absolute)) {
            // Ready but the file vanished (manual cleanup?): treat as gone.
            $db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$exec['id']]);
            Errors::gone('This result is no longer available.');
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Length: ' . (string)filesize($absolute));

        $callId = $viaCall !== null ? (string)$viaCall['id']
            : (string)$db->value('SELECT id FROM calls WHERE client_id = ? AND execution_id = ? LIMIT 1', [(int)$client['id'], $exec['id']]);
        Logger::event('downloaded', (int)$client['id'], (int)$exec['request_id'], $callId !== '' ? $callId : null,
            (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), 'result delivered');

        $chunk = max(1024, (int)Config::get('downloads.chunk_size', 65536));
        $fh = fopen($absolute, 'rb');
        if ($fh === false) {
            Errors::emit(500, 'internal_error', 'Cannot open result file.');
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
    }
}
