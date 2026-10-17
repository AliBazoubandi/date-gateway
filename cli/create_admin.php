<?php
// ============================================================================
// Create or update an admin user (CLI only).
//   php cli\create_admin.php --username=admin --password=********
//   php cli\create_admin.php --username=admin --password=******** --reset-lockout
// The password is stored with password_hash(); it is never transmitted
// through the web server.
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
$existing = $db->one('SELECT id, username, is_active FROM admin_users WHERE username = ?', [$username]);

if ($existing === null) {
    $db->execute(
        'INSERT INTO admin_users (username, password_hash, is_active, created_at) VALUES (?, ?, 1, ?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), now_str()]
    );
    echo "Admin user created: {$username} (id ", $db->insertId(), ")\n";
} else {
    $db->execute('UPDATE admin_users SET password_hash = ?, is_active = 1 WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
    echo "Password updated for: {$username} (id ", $existing['id'], ")\n";
}

if (isset($args['reset-lockout'])) {
    $db->execute('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE username = ?', [$username]);
    echo "Lockout cleared.\n";
}
