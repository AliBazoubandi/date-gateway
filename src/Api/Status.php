<?php
declare(strict_types=1);
namespace Api;

use Api\Errors;
use Core\Db;
use Core\Time;
use Services\ExecutionError;
use Services\Logger;

/** GET /api/v1/calls/{call_id} — polling endpoint, owner-only. */
final class Status
{
    public static function handle(string $callId): void
    {
        $client = ApiAuth::requireClient();
        $db = Db::instance();

        $call = $db->one('SELECT id, client_id, request_id, execution_id FROM calls WHERE id = ?', [$callId]);
        if ($call === null) {
            Errors::notFound('Unknown call.');
        }
        if ((int)$call['client_id'] !== (int)$client['id']) {
            // Never reveal other clients' calls.
            Logger::event('denied', (int)$client['id'], null, $callId, null, client_ip(), null, null,
                'status poll for foreign call');
            Errors::notFound('Unknown call.');
        }

        $out = [
            'call_id' => $callId,
            'request_code' => null,
            'status' => 'unknown',
            'row_count' => null,
            'ready_at' => null,
            'expires_at' => null,
            'link' => null,
            'error' => null,
        ];

        $request = $db->one('SELECT code FROM requests WHERE id = ?', [$call['request_id']]);
        if ($request !== null) {
            $out['request_code'] = (string)$request['code'];
        }

        if ($call['execution_id'] !== null) {
            $exec = $db->one('SELECT * FROM executions WHERE id = ?', [$call['execution_id']]);
            if ($exec !== null) {
                $status = (string)$exec['status'];
                if ($status === 'ready' && !empty($exec['expires_at']) && $exec['expires_at'] <= Time::now()) {
                    $status = 'expired';
                }
                $out['status'] = $status;
                $out['row_count'] = $exec['row_count'] !== null ? (int)$exec['row_count'] : null;
                $out['ready_at'] = $exec['ready_at'] !== null ? Time::iso((string)$exec['ready_at']) : null;
                $out['expires_at'] = $exec['expires_at'] !== null ? Time::iso((string)$exec['expires_at']) : null;
                // Failed runs say why in sanitized terms only: the driver text
                // lives in error_message and is admin-only.
                $out['error'] = ExecutionError::forExecution($exec);
                // Ready results hand the owner their stable per-call link
                // (rebuilt from the encrypted token copy; NULL for instant
                // mode or rows predating token_enc — they never had one).
                if ($status === 'ready') {
                    $out['link'] = Links::forCall($callId);
                }
            }
        }

        json_out($out);
    }
}
