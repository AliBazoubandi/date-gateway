<?php /** @var string $title @var string $csrf @var array|null $admin @var string $content
  @var string $page */
use Admin\Flashes;
use Core\Str;
use Core\Config;

$dir  = strtolower(trim((string)Config::get('app.direction', 'ltr'))) === 'rtl' ? 'rtl' : 'ltr';
$lang = (string)Config::get('app.language', 'en');
$page = isset($page) && is_string($page) ? $page : '';
$uri  = Str::e(\Core\Http::path());
$on   = static fn(string $prefix): string
    => ($prefix === '/admin' ? $uri === '/admin' : str_starts_with($uri, $prefix)) ? ' class="on" aria-current="page"' : '';
$navItem = static fn(string $prefix, string $href, string $icon, string $label): string
    => '<a href="' . $href . '"' . $on($prefix) . ' data-tip="' . $label . '" aria-label="' . $label . '">'
        . Admin\View::icon($icon, 18) . '<span>' . $label . '</span></a>';
$flashIcon = static fn(string $type): string
    => Admin\View::icon($type === 'error' ? 'alert' : ($type === 'ok' ? 'check' : 'info'), 15);
$authed = $admin !== null;

/** Worker heartbeat strip chip (worker_state; green <2min amber <5min coral). */
$beat = null;
if ($authed) {
    try {
        $pass = \Services\WorkerState::lastPass();
        $age = \Services\WorkerState::passAgeSeconds($pass);
        if ($age !== null) {
            $s = max(0, $age);
            $lbl = $s < 60 ? $s . 's ago'
                : ((int)floor($s / 60) < 60 ? (int)floor($s / 60) . 'm ago'
                : (int)floor($s / 3600) . 'h ago');
            $state = $s < 120 ? 'ok' : ($s < 300 ? 'warn' : 'bad');
            $beat = ['state' => $state, 'age' => $s, 'label' => $lbl, 'fails' => isset($pass['last_pass_failures']) ? (int)$pass['last_pass_failures'] : 0];
        }
    } catch (\Throwable) { /* a broken metadata DB must not break the page shell */ }
}
?>
<!doctype html>
<html lang="<?= Str::e($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark light">
<title><?= Str::e($title) ?> · <?= Str::e((string)Config::get('app.name', 'Data Gateway')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= \Admin\View::url('/assets/favicon.svg') ?>">
<link rel="preload" href="<?= \Admin\View::url('/assets/fonts/Geist-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= \Admin\View::url('/assets/fonts/GeistMono-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= \Admin\View::url('/assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= \Admin\View::url('/assets/css/components.css') ?>">
<script>
/* Theme before first paint: stored choice wins; else prefers-color-scheme;
   else the dark default. (tokens.css renders dark unless data-theme=light.) */
 (function () {
  var t = null;
  try { t = localStorage.getItem('gw-theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') {
    t = (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
  }
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body data-page="<?= Str::e($page) ?>" data-base="<?= Str::e(\Core\Http::basePath()) ?>" class="<?= $authed ? 'shell-page' : 'login' ?>">
<?php if (!$authed): ?>
<main class="wrap" id="content">
<?php else: ?>
<div class="shell">
<aside class="rail" id="rail" aria-label="Main navigation">
  <div class="rail-top">
    <a class="brand" href="/admin" title="Overview">
      <svg class="brand-mark" width="26" height="26" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M16 3.2 26.4 9.2v14L16 29 5.6 23.2v-14Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
        <circle cx="16" cy="16" r="3" fill="currentColor"/>
        <circle cx="16" cy="6.9" r="1.4" fill="currentColor"/>
        <circle cx="23.4" cy="20.5" r="1.4" fill="currentColor"/>
        <circle cx="8.6" cy="20.5" r="1.4" fill="currentColor"/>
      </svg>
      <span class="brand-t"><?= Str::e((string)Config::get('app.name', 'Data Gateway')) ?></span>
    </a>
    <button id="rail-pin" type="button" class="rail-pin" title="Pin / unpin rail"
        aria-pressed="false" aria-label="Pin rail open">
      <?= Admin\View::icon('pin', 14) ?>
    </button>
  </div>
  <nav class="rail-nav" aria-label="Admin panel">
    <div class="rail-section">Control room</div>
    <?= $navItem('/admin', '/admin', 'grid', 'Overview') ?>
    <?= $navItem('/admin/connections', '/admin/connections', 'database', 'Connections') ?>
    <?= $navItem('/admin/requests', '/admin/requests', 'file', 'Requests') ?>
    <?= $navItem('/admin/clients', '/admin/clients', 'users', 'Clients') ?>
    <div class="rail-section">Observe</div>
    <?= $navItem('/admin/logs', '/admin/logs', 'terminal', 'Logs') ?>
    <?= $navItem('/admin/audit', '/admin/audit', 'shield', 'Audit') ?>
    <div class="rail-section">Access</div>
    <?= $navItem('/admin/admins', '/admin/admins', 'user', 'Admin Users') ?>
  </nav>
  <div class="rail-bottom">
    <button id="palette-open" type="button" class="rail-btn"
            title="Search (Ctrl+K)" data-tip="Search" aria-label="Open quick search (Ctrl+K)">
      <?= Admin\View::icon('search', 17) ?><span>Search</span><kbd class="rail-kbd kbd">Ctrl K</kbd>
    </button>
  </div>
</aside>

<div class="content">
<header class="strip">
  <?php
    $curPath = \Core\Http::path();
    $crumbs = [
        ['label' => (string)Config::get('app.name', 'Data Gateway'), 'url' => '/admin'],
    ];
    if (str_starts_with($curPath, '/admin/requests') && $title !== 'Requests') {
        $crumbs[] = ['label' => 'Requests', 'url' => '/admin/requests'];
    } elseif (str_starts_with($curPath, '/admin/connections') && $title !== 'Connections') {
        $crumbs[] = ['label' => 'Connections', 'url' => '/admin/connections'];
    } elseif (str_starts_with($curPath, '/admin/clients') && $title !== 'Clients') {
        $crumbs[] = ['label' => 'Clients', 'url' => '/admin/clients'];
    }
  ?>
  <nav class="strip-crumb" aria-label="Breadcrumbs">
    <?php foreach ($crumbs as $c): ?>
      <a href="<?= e($c['url']) ?>" class="crumb-link"><?= Str::e($c['label']) ?></a>
      <?= Admin\View::icon('chevron-end', 12) ?>
    <?php endforeach; ?>
    <b class="crumb-here" aria-current="page"><?= Str::e($title) ?></b>
  </nav>
  <div class="strip-spacer"></div>
  <button type="button" class="strip-search" id="strip-search" aria-label="Search (Ctrl+K)">
    <?= Admin\View::icon('search', 14) ?>
    <span>Search requests, clients, pages…</span>
    <kbd class="kbd">Ctrl K</kbd>
  </button>
  <div class="strip-side">
    <?php if ($beat !== null): ?>
    <span class="beat <?= Str::e($beat['state']) ?>" id="beat" data-age="<?= (int)$beat['age'] ?>"
          title="Worker heartbeat · last pass started/finished (newest)">
      <span class="beat-dot" aria-hidden="true"></span>
      <span class="beat-label">worker · <?= Str::e($beat['label']) ?></span>
      <?php if ($beat['fails'] > 0): ?><span class="count"><?= (int)$beat['fails'] ?></span><?php endif; ?>
    </span>
    <?php endif; ?>
    <button id="theme-toggle" type="button" class="icon-btn"
            title="Switch light / dark" aria-label="Switch light / dark theme">
      <svg class="i i-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><use href="/assets/icons.svg#i-sun"></use></svg>
      <svg class="i i-moon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><use href="/assets/icons.svg#i-moon"></use></svg>
    </button>
    <div class="user-menu" id="user-menu">
      <button type="button" class="um-btn" id="um-btn" aria-haspopup="true" aria-expanded="false"
              title="Account &amp; session">
        <span class="who-av"><?= Str::e(strtoupper(substr((string)$admin['username'], 0, 2))) ?></span>
        <span class="who-name"><?= Str::e((string)$admin['username']) ?></span>
        <?= Admin\View::icon('chevron-down', 12) ?>
      </button>
      <div class="um-menu" role="menu">
        <span class="um-id"><b><?= Str::e((string)$admin['username']) ?></b><small>Admin session · audited</small></span>
        <form method="post" action="/admin/logout" class="um-logout">
          <?= $csrf ?><button class="um-item danger" type="submit"><?= Admin\View::icon('logout', 15) ?><span>Log out</span></button>
        </form>
      </div>
    </div>
  </div>
</header>
<main class="wrap" id="content">
<div class="toasts" id="toasts">
<?php foreach (Flashes::take() as $f): ?>
  <div class="toast <?= Str::e($f['type']) ?>"><?= $flashIcon((string)$f['type']) ?><span><?= Str::e($f['message']) ?></span><button type="button" class="toast-x" aria-label="Dismiss"><?= Admin\View::icon('x', 13) ?></button></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
