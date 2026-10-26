<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Admin\Flashes;
use Core\Db;
use Core\Http;
use Core\Time;
use Services\AuditLogger;
use Services\Logger;
use Worker\Cleanup;

final class ExecutionController
{
    public static function expire(int $id): void
    {
        Auth::require();
        $db = Db::instance();
        $exec = $db->one(
            'SELECT e.*, r.code AS request_code
             FROM executions e
             JOIN requests r ON r.id = e.request_id
             WHERE e.id = ?',
            [$id]
        );

        if ($exec === null) {
            if (Http::isAjax()) {
                json_out(['ok' => false, 'error' => "Execution #{$id} not found."], 404);
            }
            Flashes::set('error', "Execution #{$id} not found.");
            Http::redirect('/admin');
        }

        if ((string)$exec['status'] === 'expired') {
            if (Http::isAjax()) {
                json_out(['ok' => true, 'message' => "Execution #{$id} is already expired."]);
            }
            Flashes::set('ok', "Execution #{$id} is already expired.");
            Http::redirect('/admin');
        }

        $now = Time::now();
        if (!empty($exec['result_path'])) {
            Cleanup::safeUnlink((string)$exec['result_path']);
        }
        Cleanup::safeUnlink('results/' . $id . '.json.tmp');

        $db->execute(
            "UPDATE executions
             SET status = 'expired',
                 expires_at = ?,
                 result_path = NULL,
                 result_size_bytes = NULL,
                 finished_at = COALESCE(finished_at, ?)
             WHERE id = ?",
            [$now, $now, $id]
        );

        Logger::event(
            'expired',
            null,
            (int)$exec['request_id'],
            null,
            $id,
            null,
            null,
            $exec['row_count'] !== null ? (int)$exec['row_count'] : null,
            'manually expired by admin from overview'
        );

        AuditLogger::record(
            'execution',
            'expire',
            $id,
            (string)$exec['request_code'],
            ['status' => $exec['status'], 'expires_at' => $exec['expires_at']],
            ['status' => 'expired', 'expires_at' => $now]
        );

        if (Http::isAjax()) {
            json_out(['ok' => true, 'message' => "Execution #{$id} is expired now."]);
        }

        Flashes::set('ok', "Execution #{$id} is expired now.");
        Http::redirect('/admin');
    }
}
