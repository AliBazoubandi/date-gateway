<?php
// ============================================================================
// Create or update an admin user (CLI only).
//   php cli\create_admin.php --username=admin --password=********
//   php cli\create_admin.php --username=admin --password=******** --reset-lockout
// The password is stored with password_hash(); it is never transmitted
// through the web server. Replacing a password also advances the account's
// session_version, so every admin session signed in with the old password is
// dropped on its next request. Both actions land in admin_audit_log.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';
require __DIR__ . '/../src/Core/Filesystem.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_-]+)=(.*)$/i', $a, $m)) {
        $args[strtolower($m[1])] = $m[2];
    } elseif (preg_match('/^--([a-z_-]+)$/i', $a, $m)) {
        $args[strtolower($m[1])] = '';
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$username = (string)($args['username'] ?? '');
$password = (string)($args['password'] ?? '');
if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php cli\\create_admin.php --username=NAME --password=PASS [--reset-lockout]\n");
    exit(2);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(2);
}

$db = db();
$existing = $db->one('SELECT id, username, is_active, session_version FROM admin_users WHERE username = ?', [$username]);

if ($existing === null) {
    $db->execute(
        'INSERT INTO admin_users (username, password_hash, is_active, created_at) VALUES (?, ?, 1, ?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), now_str()]
    );
    $id = (int)$db->insertId();
    echo "Admin user created: {$username} (id {$id})\n";
    \Services\AuditLogger::record('admin', 'create', $id, $username, null,
        ['username' => $username, 'via' => 'cli'], 0);
} else {
    // Replacing the password also advances session_version, which invalidates
    // every admin session that was issued before this run.
    $db->execute(
        'UPDATE admin_users SET password_hash = ?, is_active = 1, session_version = session_version + 1 WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $existing['id']]
    );
    $id = (int)$existing['id'];
    echo "Password updated for: {$username} (id {$id})\n";
    \Services\AuditLogger::record('admin', 'password_change', $id, $username, null,
        ['session_version' => (int)$existing['session_version'] + 1, 'via' => 'cli'], 0);
}

if (isset($args['reset-lockout'])) {
    $db->execute('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE username = ?', [$username]);
    echo "Lockout cleared.\n";
}
