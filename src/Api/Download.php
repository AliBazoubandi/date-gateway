<?php
declare(strict_types=1);
namespace Api;

use Api\Errors;
use Core\Config;
use Core\Db;
use Core\Filesystem;
use Core\Time;
use Services\Logger;
use Services\ResultArchive;

/**
 * GET /api/v1/results/{token} — chunked, time-limited JSON delivery.
 * Needs: the raw token + the client's Bearer key + a call by THAT client
 * attached to the execution. Refused when expired (410 status, no body —
 * see Api\Errors::refusedDelivery), 404 when unknown/foreign.
 * The streamed body carries Content-Disposition under the run's
 * deterministic name (="{request code}_{expiry stamp}_client{n}_e{id}.json",
 * the same name the archive keeps for it). The same streaming path serves
 * instant submissions when they are called with ?download=1.
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
     * instant submissions called with ?download=1. Guards status/expiry and
     * streams the JSON envelope file chunk by chunk. An expired result is
     * REFUSED as headers + a deliberately undelivered body
     * (Api\Errors::refusedDelivery): no bytes are ever streamed, so a plain
     * `curl -o` re-run after expiry fails visibly instead of overwriting the
     * client's previously saved download with an error payload.
     *
     * @param array<string,mixed> $exec a full executions row
     */
    public static function streamResult(array $exec, ?string $callId, string $ip, string $note): never
    {
        $db = Db::instance();
        $clientId = (int)$db->value('SELECT client_id FROM calls WHERE id = ? LIMIT 1', [$callId ?? '0']);
        $status = (string)$exec['status'];

        if ($status === 'expired') {
            self::purgeExpiredFile($exec); // the row may predate cleanup's archive pass
            Logger::event('expired', $clientId, (int)$exec['request_id'], $callId, (int)$exec['id'], $ip,
                null, (int)($exec['row_count'] ?? 0), 'download after expiry');
            Errors::refusedDelivery('expired', 'This result has expired.');
        }
        if ($status !== 'ready') {
            Errors::notFound('Unknown token.');
        }
        if (!empty($exec['ready_at']) && (string)$exec['ready_at'] > Time::now()) {
            // Finished before the delay promise opened: the result exists but
            // its window has not. Retryable, with the exact wait.
            $retryAfter = max(1, Time::fromSql((string)$exec['ready_at'])->getTimestamp() - time());
            Errors::emit(409, 'result_not_ready',
                'This result is ready but its download window has not opened yet.',
                ['Retry-After' => (string)$retryAfter]);
        }
        if (empty($exec['expires_at']) || $exec['expires_at'] <= Time::now()) {
            // Expired between cleanup passes: archive the file BEFORE the
            // refusal (kept, but no longer at its downloadable path — the
            // sweeper would do the same within minutes; this answers first).
            // A ready row with NO expires_at fails closed the same way:
            // without a stamp there is no way to prove the window is still
            // open, so nothing streams.
            self::purgeExpiredFile($exec, true);
            Logger::event('expired', $clientId, (int)$exec['request_id'], $callId,
                (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), 'expired during download');
            Errors::refusedDelivery('expired', 'This result has expired.');
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
            // Ready but the file vanished (manual cleanup?): treat as gone —
            // nothing can be delivered, and the refusal never writes a body.
            $db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$exec['id']]);
            Errors::refusedDelivery('expired', 'This result is no longer available.');
        }

        $code = (string)$db->value('SELECT code FROM requests WHERE id = ?', [$exec['request_id']]);
        // The delivered file carries the SAME deterministic name the archive
        // uses for this run (ResultArchive::nameFor; the client is the one
        // whose call first triggered the execution, like the evidence copy),
        // so a saved download and its archived twin match by name.
        $origClient = $db->value(
            'SELECT client_id FROM calls WHERE execution_id = ? ORDER BY created_at ASC, id ASC LIMIT 1',
            [(int)$exec['id']]
        );
        $filename = ResultArchive::nameFor(
            (int)$exec['id'],
            $code,
            $origClient !== null ? (int)$origClient : null,
            (string)$exec['expires_at']
        );

        // Prevent PHP script execution timeout on slow internet connections
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        // Clear any active output buffers to allow immediate chunk streaming
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $format = strtolower(trim((string)(\Core\Http::param('format', 'g') ?? '')));
        $isZip = $format === 'zip'
            || \Core\Http::param('zip', 'g') === '1'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/zip');
        $isGzip = $format === 'gzip'
            || $format === 'gz'
            || \Core\Http::param('gzip', 'g') === '1'
            || \Core\Http::param('gz', 'g') === '1';

        Logger::event('downloaded', $clientId, (int)$exec['request_id'], $callId,
            (int)$exec['id'], $ip, null, (int)($exec['row_count'] ?? 0), $note . ($isZip ? ' (zip)' : ($isGzip ? ' (gzip)' : '')));

        if ($isZip && class_exists('\ZipArchive')) {
            $zipTemp = tempnam(sys_get_temp_dir(), 'gw_zip_');
            $zip = new \ZipArchive();
            if ($zip->open($zipTemp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $zip->addFile($absolute, $filename);
                $zip->close();

                $zipFilename = preg_replace('/\.json$/i', '', $filename) . '.zip';
                header('Content-Type: application/zip');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-store');
                header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
                header('Content-Length: ' . (string)filesize($zipTemp));

                $fh = fopen($zipTemp, 'rb');
                if ($fh !== false) {
                    while (!feof($fh)) {
                        $buf = fread($fh, 65536);
                        if ($buf === false || $buf === '') {
                            break;
                        }
                        echo $buf;
                        flush();
                    }
                    fclose($fh);
                }
                @unlink($zipTemp);
                exit;
            }
        }

        if ($isGzip && function_exists('gzopen')) {
            $gzFilename = $filename . '.gz';
            header('Content-Type: application/gzip');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="' . $gzFilename . '"');

            $in = fopen($absolute, 'rb');
            if ($in !== false) {
                $out = gzopen('php://output', 'wb9');
                if ($out !== false) {
                    while (!feof($in)) {
                        $buf = fread($in, 65536);
                        if ($buf === false || $buf === '') {
                            break;
                        }
                        gzwrite($out, $buf);
                    }
                    gzclose($out);
                }
                fclose($in);
            }
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)filesize($absolute));

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
     * Archive the on-disk result of an execution that is being refused as
     * expired: move it to storage/archive/ first (it stays as evidence and is
     * not downloadable either way — the 410 above is the access check), then
     * clear the stored reference (and mark the row expired when it was caught
     * by the TTL race). A racing re-read then self-heals to 410 and no
     * downloadable result survives at its published path.
     * @param array<string,mixed> $exec a full executions row
     */
    private static function purgeExpiredFile(array $exec, bool $markExpired = false): void
    {
        if (!empty($exec['result_path'])) {
            // File under the originating client's name (same deterministic
            // name the ready-time copy and the cleanup sweep use), so a run
            // archives to exactly one evidence file whoever triggers it.
            $origClient = Db::instance()->value(
                'SELECT client_id FROM calls WHERE execution_id = ? ORDER BY created_at ASC, id ASC LIMIT 1',
                [(int)$exec['id']]
            );
            ResultArchive::storeResult(
                (string)$exec['result_path'],
                (int)$exec['id'],
                (string)Db::instance()->value('SELECT code FROM requests WHERE id = ?', [$exec['request_id']]),
                $origClient !== null ? (int)$origClient : null,
                (string)($exec['expires_at'] ?? Time::now())
            );
        }
        $sql = "UPDATE executions SET result_path = NULL, result_size_bytes = NULL";
        if ($markExpired) {
            $sql .= ", status = 'expired'";
        }
        Db::instance()->execute($sql . ' WHERE id = ?', [(int)$exec['id']]);
    }
}
