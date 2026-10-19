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
use Worker\Cleanup;

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

        // A per-call minted token is the only download credential: no code
        // path ever handed out an execution-level token, so the former
        // executions.download_token_hash lookup was unreachable except by
        // guessing 48 hex characters.
        $viaCall = $db->one('SELECT id, client_id, execution_id FROM calls WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($viaCall === null) {
            Errors::notFound('Unknown token.');
        }
        if ((int)$viaCall['client_id'] !== (int)$client['id']) {
            // Same 404 as an unknown token (no existence leak) but audited.
            Logger::event('denied', (int)$client['id'], null, (string)$viaCall['id'], null, $ip,
                null, null, 'download attempt with another client token');
            Errors::notFound('Unknown token.');
        }
        $viaCallId = (string)$viaCall['id'];
        $exec = $viaCall['execution_id'] !== null
            ? $db->one('SELECT * FROM executions WHERE id = ?', [$viaCall['execution_id']])
            : null;
        if ($exec === null) {
            Errors::notFound('Unknown token.');
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
            self::purgeExpiredFile($exec); // the row may predate cleanup's expiry pass
            Logger::event('expired', $clientId, (int)$exec['request_id'], $callId, (int)$exec['id'], $ip,
                null, (int)($exec['row_count'] ?? 0), 'download after expiry');
            Errors::gone('This result has expired.');
        }
        if ($status !== 'ready') {
            Errors::notFound('Unknown token.');
        }
        if (!empty($exec['expires_at']) && $exec['expires_at'] <= Time::now()) {
            // Expired between cleanup passes: delete the file BEFORE the 410
            // so no expired result survives on disk waiting for the sweeper.
            self::purgeExpiredFile($exec, true);
            Logger::event('expired', $clientId, (int)$exec['request_id'], $callId,
                (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), 'expired during download');
            Errors::gone('This result has expired.');
        }

        $absolute = '';
        if ($exec['result_path'] !== null) {
            try {
                $absolute = Filesystem::resultPath((string)$exec['result_path']);
            } catch (\RuntimeException) {
                $absolute = ''; // untrusted/tampered path: treat as missing file below
            }
        }
        if ($absolute === '' || !is_file($absolute)) {
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

    /**
     * Delete the on-disk result of an execution that is being refused as
     * expired: unlink first, then clear the stored reference (and mark the
     * row expired when it was caught by the TTL race). A racing re-read then
     * self-heals to 410 and no expired file survives on disk.
     * @param array<string,mixed> $exec a full executions row
     */
    private static function purgeExpiredFile(array $exec, bool $markExpired = false): void
    {
        if (!empty($exec['result_path'])) {
            Cleanup::safeUnlink((string)$exec['result_path']);
        }
        $sql = "UPDATE executions SET result_path = NULL, result_size_bytes = NULL";
        if ($markExpired) {
            $sql .= ", status = 'expired'";
        }
        Db::instance()->execute($sql . ' WHERE id = ?', [(int)$exec['id']]);
    }
}
