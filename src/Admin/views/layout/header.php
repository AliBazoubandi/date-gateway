<?php /** @var string $title @var string $csrf @var array|null $admin */
use Admin\Flashes;
use Core\Str;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= Str::e($title) ?> · Data Gateway</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/styles.css">
<script>
/* Theme init before first paint: stored choice wins, else system preference. */
(function () {
  var dark = false;
  try {
    var saved = localStorage.getItem('gw-theme');
    dark = saved ? saved === 'dark'
                 : window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
  } catch (e) {}
  if (dark) { document.documentElement.classList.add('dark'); }
})();
</script>
</head>
<body>
<header class="topbar">
  <div class="wrap">
    <span class="brand">
      <svg class="brand-mark" width="24" height="24" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M16 3.2 26.4 9.2v14L16 29 5.6 23.2v-14Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
        <circle cx="16" cy="16" r="3" fill="currentColor"/>
        <circle cx="16" cy="6.9" r="1.4" fill="currentColor"/>
        <circle cx="23.4" cy="20.5" r="1.4" fill="currentColor"/>
        <circle cx="8.6" cy="20.5" r="1.4" fill="currentColor"/>
      </svg>
      Data Gateway
    </span>
    <nav aria-label="Admin panel">
      <?php $uri = Str::e($_SERVER['REQUEST_URI'] ?? ''); ?>
      <a href="/admin" <?= $uri === '/admin' ? 'class="on"' : '' ?>>Dashboard</a>
      <a href="/admin/connections" <?= str_starts_with($uri, '/admin/connections') ? 'class="on"' : '' ?>>Connections</a>
      <a href="/admin/requests" <?= str_starts_with($uri, '/admin/requests') ? 'class="on"' : '' ?>>Requests</a>
      <a href="/admin/clients" <?= str_starts_with($uri, '/admin/clients') ? 'class="on"' : '' ?>>Clients</a>
      <a href="/admin/logs" <?= str_starts_with($uri, '/admin/logs') ? 'class="on"' : '' ?>>Logs</a>
      <a href="/admin/audit" <?= str_starts_with($uri, '/admin/audit') ? 'class="on"' : '' ?>>Audit</a>
    </nav>
    <div class="who">
      <button id="theme-toggle" type="button" class="theme-toggle"
              title="Switch light / dark" aria-label="Switch light / dark theme">
        <svg class="i-sun" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <g stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none">
            <circle cx="12" cy="12" r="4.2" fill="currentColor" stroke="none"/>
            <path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.1 5.1l1.6 1.6M17.3 17.3l1.6 1.6M18.9 5.1l-1.6 1.6M6.7 17.3l-1.6 1.6"/>
          </g>
        </svg>
        <svg class="i-moon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M20.6 13.4A8.6 8.6 0 1 1 10.6 3.4a7 7 0 0 0 10 10Z" fill="currentColor"/>
        </svg>
      </button>
      <?php if ($admin !== null): ?>
      <span class="who-user"><?= Str::e((string)$admin['username']) ?></span>
      <form method="post" action="/admin/logout"><?= $csrf ?><button class="link">Logout</button></form>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="wrap">
<?php foreach (Flashes::take() as $f): ?>
  <div class="flash <?= Str::e($f['type']) ?>"><?= Str::e($f['message']) ?></div>
<?php endforeach; ?>