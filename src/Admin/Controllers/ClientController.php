<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Flashes;
use Admin\View;
use Core\Db;
use Core\Http;
use Core\Str;
use Core\Time;
use Services\AuditLogger;
use Services\WebhookUrl;

final class ClientController
{
    public static function list(): void
    {
        $db = Db::instance();
        $rows = $db->rows(
            'SELECT c.id, c.name, c.type, c.is_active, c.webhook_url, c.created_at,
                    (SELECT COUNT(*) FROM client_request_permissions p WHERE p.client_id = c.id) AS permissions_count
             FROM clients c ORDER BY c.name'
        );
        View::render('clients/list', ['rows' => $rows], 'Clients');
    }

    /** @param int $id 0 = new */
    public static function edit(int $id): void
    {
        $db = Db::instance();
        $row = $id > 0 ? $db->one('SELECT * FROM clients WHERE id = ?', [$id]) : null;
        if ($row === null && $id > 0) {
            Flashes::set('error', 'Client not found.');
            Http::redirect('/admin/clients');
        }
        $requests = $db->rows('SELECT id, code, name, response_mode FROM requests WHERE is_active = 1 ORDER BY code');
        $granted = [];
        if ($id > 0) {
            foreach ($db->rows('SELECT request_id FROM client_request_permissions WHERE client_id = ?', [$id]) as $g) {
                $granted[(int)$g['request_id']] = true;
            }
        }
        View::render('clients/edit', ['row' => $row ?? self::defaults(), 'requests' => $requests, 'granted' => $granted],
            $row === null ? 'New client' : 'Edit client');
    }

    public static function save(int $id): void
    {
        $db = Db::instance();
        $in = self::input();
        $errors = self::validate($in, $id);
        if ($errors !== []) {
            Flashes::set('error', implode(' ', $errors));
            Http::redirect($id > 0 ? '/admin/clients/' . $id : '/admin/clients/new');
        }
        $adminId = (int)($_SESSION['admin_user_id'] ?? 0);
        $now = Time::now();

        if ($id > 0) {
            $before = $db->one('SELECT * FROM clients WHERE id = ?', [$id]);
            // The form never renders the stored secret, so an empty field
            // means "keep it"; only a non-empty value rotates the secret.
            $keepSecret = $in['webhook_secret'] === '';
            $db->execute(
                'UPDATE clients SET name=?, type=?, webhook_url=?, webhook_secret=?, is_active=? WHERE id=?',
                [$in['name'], $in['type'], $in['webhook_url'],
                    $keepSecret ? (string)($before['webhook_secret'] ?? '') : $in['webhook_secret'],
                    $in['is_active'], $id]
            );
            $after = $db->one('SELECT * FROM clients WHERE id = ?', [$id]);
            $secretChanged = !$keepSecret;
            $afterAudit = self::mask($after, false, $secretChanged);
            AuditLogger::record('client', 'update', $id, $in['name'], self::mask($before, false, false), $afterAudit);
            Flashes::set('ok', 'Client updated.');
            Http::redirect('/admin/clients/' . $id);
        }

        // Create: generate the API key here and show it exactly once.
        $apiKey = 'gw_' . Str::hex(24); // 51 chars incl. prefix; only SHA-256 is stored
        $secret = Str::hex(24);
        $db->execute(
            'INSERT INTO clients (name, type, api_key_hash, webhook_url, webhook_secret, is_active, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$in['name'], $in['type'], hash('sha256', $apiKey), $in['webhook_url'], $in['webhook_secret'] ?: $secret, $in['is_active'], $adminId, $now]
        );
        $newId = $db->insertId();
        $after = self::mask($db->one('SELECT * FROM clients WHERE id = ?', [$newId]), true, false);
        AuditLogger::record('client', 'create', $newId, $in['name'], null, $after);
        // An auto-generated secret must be shown once, or nobody can ever
        // configure the client's signature verification with it.
        View::render('clients/key_reveal', ['client' => $in, 'api_key' => $apiKey,
            'webhook_secret' => $in['webhook_secret'] !== '' ? null : $secret], 'API key created');
    }

    public static function delete(int $id): void
    {
        $db = Db::instance();
        $row = $db->one('SELECT * FROM clients WHERE id = ?', [$id]);
        if ($row === null) {
            Flashes::set('error', 'Client not found.');
            Http::redirect('/admin/clients');
        }
        $calls = (int)$db->value('SELECT COUNT(*) FROM calls WHERE client_id = ?', [$id]);
        if ($calls > 0) {
            Flashes::set('error', "Client has {$calls} call(s) of history. Deactivate it instead of deleting.");
            Http::redirect('/admin/clients');
        }
        $db->execute('DELETE FROM clients WHERE id = ?', [$id]); // permissions cascade
        AuditLogger::record('client', 'delete', $id, (string)$row['name'], self::mask($row, false, false), null);
        Flashes::set('ok', 'Client deleted.');
        Http::redirect('/admin/clients');
    }

    /** Issue a new API key; the old one stops working immediately. */
    public static function regenerateKey(int $id): void
    {
        $db = Db::instance();
        $row = $db->one('SELECT id, name, api_key_hash FROM clients WHERE id = ?', [$id]);
        if ($row === null) {
            Flashes::set('error', 'Client not found.');
            Http::redirect('/admin/clients');
        }
        $apiKey = 'gw_' . Str::hex(24);
        $db->execute('UPDATE clients SET api_key_hash = ? WHERE id = ?', [hash('sha256', $apiKey), $id]);
        $afterAudit = ['id' => $id, 'name' => $row['name'], 'api_key' => '(changed)'];
        AuditLogger::record('client', 'update', $id, (string)$row['name'], ['api_key' => '(unchanged)'], $afterAudit);
        View::render('clients/key_reveal', ['client' => ['name' => $row['name']], 'api_key' => $apiKey,
            'regenerated' => true], 'New API key');
    }

    /** Rotate the webhook secret; the new value is shown exactly once. */
    public static function newSecret(int $id): void
    {
        $db = Db::instance();
        $row = $db->one('SELECT id, name FROM clients WHERE id = ?', [$id]);
        if ($row === null) {
            Flashes::set('error', 'Client not found.');
            Http::redirect('/admin/clients');
        }
        $secret = Str::hex(24);
        $db->execute('UPDATE clients SET webhook_secret = ? WHERE id = ?', [$secret, $id]);
        AuditLogger::record('client', 'update', $id, (string)$row['name'],
            ['webhook_secret' => '(stored value)'],
            ['webhook_secret' => '(rotated; shown once)']);
        View::render('clients/secret_reveal', ['client' => $row, 'webhook_secret' => $secret],
            'New webhook secret');
    }

    /** Grant/revoke request permissions for one client; audited as a summary diff. */
    public static function savePermissions(int $id): void
    {
        $db = Db::instance();
        $client = $db->one('SELECT id, name FROM clients WHERE id = ?', [$id]);
        if ($client === null) {
            Flashes::set('error', 'Client not found.');
            Http::redirect('/admin/clients');
        }
        $adminId = (int)($_SESSION['admin_user_id'] ?? 0);
        $posted = [];
        if (is_array($_POST['request_ids'] ?? null)) {
            foreach ($_POST['request_ids'] as $rid) {
                if (ctype_digit((string)$rid)) {
                    $posted[(int)$rid] = true;
                }
            }
        }
        // Only active, existing requests are grantable.
        $valid = [];
        foreach ($db->rows('SELECT id, code FROM requests WHERE is_active = 1') as $r) {
            $valid[(int)$r['id']] = (string)$r['code'];
        }
        $current = [];
        foreach ($db->rows('SELECT request_id FROM client_request_permissions WHERE client_id = ?', [$id]) as $g) {
            $current[(int)$g['request_id']] = true;
        }

        $added = [];
        $removed = [];
        $now = Time::now();
        foreach ($posted as $rid => $_) {
            if (!isset($valid[$rid])) {
                continue;
            }
            if (!isset($current[$rid])) {
                $db->execute(
                    'INSERT INTO client_request_permissions (client_id, request_id, granted_by, granted_at) VALUES (?, ?, ?, ?)',
                    [$id, $rid, $adminId, $now]
                );
                $added[] = $valid[$rid];
            }
        }
        foreach ($current as $rid => $_) {
            if (!isset($posted[$rid])) {
                $db->execute('DELETE FROM client_request_permissions WHERE client_id = ? AND request_id = ?', [$id, $rid]);
                $removed[] = $valid[$rid] ?? ('#' . $rid);
            }
        }

        // BEFORE snapshot: the codes granted prior to this change.
        $beforeCodes = array_values(array_map(static fn($rid) => $valid[$rid] ?? ('#' . $rid), array_keys($current)));
        AuditLogger::record('permission', 'update', $id, (string)$client['name'],
            ['granted' => $beforeCodes],
            ['added' => $added, 'removed' => $removed]);

        Flashes::set('ok', 'Permissions saved (' . count($added) . ' granted, ' . count($removed) . ' revoked).');
        Http::redirect('/admin/clients/' . $id);
    }

    /** ------------------------------------------------------------------ */

    private static function defaults(): array
    {
        return ['id' => 0, 'name' => '', 'type' => 'agent', 'is_active' => 1,
            'webhook_url' => '', 'webhook_secret' => '', 'api_key_hash' => ''];
    }

    private static function input(): array
    {
        $type = strtolower(trim((string)Http::param('type', 'p')));
        return [
            'name' => trim((string)Http::param('name', 'p')),
            'type' => in_array($type, ['agent', 'human'], true) ? $type : 'agent',
            'is_active' => Http::param('is_active', 'p') !== null ? 1 : 0,
            'webhook_url' => trim((string)(Http::param('webhook_url', 'p') ?? '')),
            'webhook_secret' => trim((string)(Http::param('webhook_secret', 'p') ?? '')),
        ];
    }

    private static function validate(array $in, int $id): array
    {
        $db = Db::instance();
        $e = [];
        if ($in['name'] === '') {
            $e[] = 'Name is required.';
        }
        if ($db->value('SELECT id FROM clients WHERE name = ? AND id <> ?', [$in['name'], $id]) !== null) {
            $e[] = 'Name already exists.';
        }
        if ($in['webhook_url'] !== '') {
            // Full SSRF gate, not just a prefix check: an admin must not be
            // able to point the gateway at its own host, a metadata endpoint
            // or any other address the policy blocks (Services\WebhookUrl).
            $error = WebhookUrl::check($in['webhook_url'])['error'];
            if ($error !== '') {
                $e[] = $error;
            }
        }
        return $e;
    }

    /** Never let the API key or webhook secret reach the audit JSON. */
    private static function mask(?array $row, bool $isNew, bool $secretChanged): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = $row;
        $out['api_key'] = $isNew ? '(generated, shown once)' : '(unchanged)';
        unset($out['api_key_hash']);
        $out['webhook_secret'] = $secretChanged ? '(changed to a new value; stored as is)' : '(stored as is)';
        return $out;
    }
}
