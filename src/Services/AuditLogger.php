<?php
declare(strict_types=1);
namespace Services;

use Core\Db;
use Core\Session;
use Core\Str;
use Core\Time;

/**
 * Append-only audit trail. Snapshots hold the FULL record; the viewer derives
 * the changed fields. Callers MUST mask secrets in their snapshots before
 * passing them here (see ConnectionController).
 */
final class AuditLogger
{
    public static function record(
        string $entityType,
        string $action,
        ?int $entityId,
        ?string $entityName,
        ?array $before,
        ?array $after,
        ?int $adminId = null
    ): void {
        // Explicit parameter wins; otherwise fall back to the session
        // (CLI/test callers may have no session and record admin_user_id NULL).
        if ($adminId === null) {
            Session::start();
            $adminId = isset($_SESSION['admin_user_id']) ? (int)$_SESSION['admin_user_id'] : null;
        }
        Db::instance()->execute(
            'INSERT INTO admin_audit_log
                (timestamp, admin_user_id, entity_type, entity_id, entity_name, action, before_json, after_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Time::now(),
                $adminId !== null && $adminId > 0 ? $adminId : null,
                $entityType,
                $entityId,
                $entityName,
                $action,
                $before !== null ? Str::json($before) : null,
                $after !== null ? Str::json($after) : null,
            ]
        );
    }
}
