<?php /** @var string $title @var string $csrf @var array|null $admin */
use Admin\Flashes;
use Core\Str;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= Str::e($title) ?> · Data Gateway</title>
<link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<header class="topbar">
  <div class="wrap">
    <span class="brand">Data Gateway</span>
    <nav>
      <a href="/admin" <?= (Str::e($_SERVER['REQUEST_URI'] ?? '') === '/admin') ? 'class="on"' : '' ?>>Dashboard</a>
      <a href="/admin/connections">Connections</a>
      <a href="/admin/requests">Requests</a>
      <a href="/admin/clients">Clients</a>
      <a href="/admin/logs">Logs</a>
      <a href="/admin/audit">Audit</a>
    </nav>
    <?php if ($admin !== null): ?>
    <div class="who">
      <span><?= Str::e((string)$admin['username']) ?></span>
      <form method="post" action="/admin/logout"><?= $csrf ?><button class="link">Logout</button></form>
    </div>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach (Flashes::take() as $f): ?>
  <div class="flash <?= Str::e($f['type']) ?>"><?= Str::e($f['message']) ?></div>
<?php endforeach; ?>
