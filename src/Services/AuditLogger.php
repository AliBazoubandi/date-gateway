<?php
declare(strict_types=1);
namespace Services;

use Core\Db;
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
        ?array $after
    ): void {
        Db::instance()->execute(
            'INSERT INTO admin_audit_log
                (timestamp, admin_user_id, entity_type, entity_id, entity_name, action, before_json, after_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Time::now(),
                (int)($_SESSION['admin_user_id'] ?? 0) ?: null,
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
