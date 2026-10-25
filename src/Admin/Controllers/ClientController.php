<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Admin\Flashes;
use Admin\View;
use Core\ApiKeyHash;
use Core\Db;
use Core\Http;
use Core\Str;
use Core\Time;
use Services\AuditLogger;
use Services\Encryption;
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
        self::renderEdit($db, $row ?? self::defaults(), $id, []);
    }

    /** The edit form: a DB row, or the rejected POST input with field errors. */
    private static function renderEdit(Db $db, array $row, int $id, array $errors): void
    {
        $requests = $db->rows('SELECT id, code, name, response_mode FROM requests WHERE is_active = 1 ORDER BY code');
        $granted = [];
        if ($id > 0) {
            foreach ($db->rows('SELECT request_id FROM client_request_permissions WHERE client_id = ?', [$id]) as $g) {
                $granted[(int)$g['request_id']] = true;
            }
        }
        View::render('clients/edit', ['row' => $row, 'requests' => $requests, 'granted' => $granted, 'errors' => $errors],
            $row['id'] ?? 0 ? 'Edit client' : 'New client', 'client-edit');
    }

    public static function save(int $id): void
    {
        $db = Db::instance();
        $in = self::input();
        $errors = self::validate($in, $id);
        if ($errors !== []) {
            // Re-render with everything the admin typed plus a red border on
            // the offending field — a redirect would blank the whole form.
            self::renderEdit($db, ['id' => $id] + $in, $id, $errors);
            return;
        }
        $adminId = (int)(Auth::current()['id'] ?? 0);
        $now = Time::now();

        if ($id > 0) {
            $before = $db->one('SELECT * FROM clients WHERE id = ?', [$id]);
            // The form never renders the stored secret, so an empty field
            // means "keep it"; only a non-empty value rotates the secret.
            $keepSecret = $in['webhook_secret'] === '';
            $db->execute(
                'UPDATE clients SET name=?, type=?, webhook_url=?, webhook_secret_enc=?, is_active=? WHERE id=?',
                [$in['name'], $in['type'], $in['webhook_url'],
                    $keepSecret ? (string)($before['webhook_secret_enc'] ?? '') : Encryption::encrypt($in['webhook_secret']),
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
        $apiKey = 'gw_' . Str::hex(24); // 51 chars incl. prefix; only hashes are stored
        $secret = Str::hex(24);
        $db->execute(
            'INSERT INTO clients (name, type, api_key_hash, api_key_hash_hmac, webhook_url, webhook_secret_enc, is_active, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$in['name'], $in['type'], ApiKeyHash::legacy($apiKey), ApiKeyHash::peppered($apiKey),
                $in['webhook_url'], Encryption::encrypt($in['webhook_secret'] ?: $secret),
                $in['is_active'], $adminId, $now]
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
        $running = (int)$db->value(
            "SELECT COUNT(*) FROM executions e JOIN calls c ON c.execution_id = e.id WHERE c.client_id = ? AND e.status = 'running'",
            [$id]
        );
        if ($running > 0) {
            Flashes::set('error', 'Cannot delete client: active queries are currently executing for this client.');
            Http::redirect('/admin/clients');
        }
        $db->execute('DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE client_id = ?)', [$id]);
        $db->execute('DELETE FROM calls WHERE client_id = ?', [$id]);
        $db->execute('DELETE FROM client_rate_limits WHERE client_id = ?', [$id]);
        $db->execute('DELETE FROM client_request_permissions WHERE client_id = ?', [$id]);
        $db->execute('DELETE FROM request_logs WHERE client_id = ?', [$id]);
        $db->execute('DELETE FROM clients WHERE id = ?', [$id]);
        AuditLogger::record('client', 'delete', $id, (string)$row['name'], self::mask($row, false, false), null);
        Flashes::set('ok', 'Client «' . $row['name'] . '» deleted.');
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
        // Both hash forms are written: the bare SHA-256 must stop matching the
        // old key immediately, and the peppered HMAC is the new primary lookup.
        $db->execute('UPDATE clients SET api_key_hash = ?, api_key_hash_hmac = ? WHERE id = ?',
            [ApiKeyHash::legacy($apiKey), ApiKeyHash::peppered($apiKey), $id]);
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
        // Encrypted at rest (AES-256-GCM under the master key); the plaintext
        // exists only here, on the one-time reveal page.
        $db->execute('UPDATE clients SET webhook_secret_enc = ? WHERE id = ?',
            [Encryption::encrypt($secret), $id]);
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
        $adminId = (int)(Auth::current()['id'] ?? 0);
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
            'webhook_url' => '', 'webhook_secret_enc' => '', 'api_key_hash' => ''];
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

    /** @return array<string,string> field name => error message */
    private static function validate(array $in, int $id): array
    {
        $db = Db::instance();
        $e = [];
        if ($in['name'] === '') {
            $e['name'] = 'Name is required.';
        }
        if ($db->value('SELECT id FROM clients WHERE name = ? AND id <> ?', [$in['name'], $id]) !== null) {
            $e['name'] = 'Name already exists.';
        }
        if ($in['webhook_url'] !== '') {
            // Full SSRF gate, not just a prefix check: an admin must not be
            // able to point the gateway at its own host, a metadata endpoint
            // or any other address the policy blocks (Services\WebhookUrl).
            $error = WebhookUrl::check($in['webhook_url'])['error'];
            if ($error !== '') {
                $e['webhook_url'] = $error;
            }
        }
        if (strlen($in['webhook_secret']) > 128) {
            // Kept at the legacy storage cap: 128 chars turn into a ~220-char
            // gcm:v1: blob, which fits webhook_secret_enc VARCHAR(255).
            $e['webhook_secret'] = 'Webhook secret is too long (128 characters max).';
        }
        return $e;
    }

    /** Never let the API key, webhook secret or their hashes reach the audit JSON. */
    private static function mask(?array $row, bool $isNew, bool $secretChanged): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = $row;
        $out['api_key'] = $isNew ? '(generated, shown once)' : '(unchanged)';
        unset($out['api_key_hash'], $out['api_key_hash_hmac']);
        unset($out['webhook_secret_enc']);
        $out['webhook_secret'] = $secretChanged
            ? '(changed to a new value; encrypted at rest)'
            : '(encrypted at rest)';
        return $out;
    }
}
