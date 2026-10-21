<?php /** @var array $counts @var array $recent */
use Core\Str;
$act = $counts['activity'] ?? [];
?>
<h1>Dashboard</h1>
<section class="stats" aria-label="Gateway at a glance">
  <a class="stat" href="/admin/requests">
    <span class="stat-n"><?= (int)$counts['requests_active'] ?></span>
    <span class="stat-l">active requests</span>
    <?php if ((int)$counts['requests_active'] !== (int)$counts['requests']): ?>
      <span class="stat-sub">of <?= (int)$counts['requests'] ?> total</span>
    <?php endif; ?>
  </a>
  <a class="stat" href="/admin/connections">
    <span class="stat-n"><?= (int)$counts['connections'] ?></span>
    <span class="stat-l">connections</span>
  </a>
  <a class="stat" href="/admin/clients">
    <span class="stat-n"><?= (int)$counts['clients'] ?></span>
    <span class="stat-l">clients</span>
  </a>
  <div class="stat">
    <span class="stat-n"><?= (int)($act['scheduled'] ?? 0) ?></span>
    <span class="stat-l">scheduled now</span>
  </div>
  <div class="stat<?= (int)($act['running'] ?? 0) > 0 ? ' live' : '' ?>">
    <span class="stat-n"><?= (int)($act['running'] ?? 0) ?></span>
    <span class="stat-l">running now</span>
  </div>
  <div class="stat">
    <span class="stat-n"><?= (int)($act['ready'] ?? 0) ?></span>
    <span class="stat-l">ready now</span>
  </div>
  <div class="stat<?= (int)($act['failed_24h'] ?? 0) > 0 ? ' alarm' : '' ?>">
    <span class="stat-n"><?= (int)($act['failed_24h'] ?? 0) ?></span>
    <span class="stat-l">failed · last 24h</span>
  </div>
  <div class="stat">
    <span class="stat-n"><?= (int)($act['calls_24h'] ?? 0) ?></span>
    <span class="stat-l">calls · last 24h</span>
  </div>
</section>
<section class="card">
  <h2>Latest log entries</h2>
  <?php if ($recent === []): ?><p>No log entries yet.</p><?php else: ?>
  <table class="rows">
    <thead>
    <tr><th>When</th><th>Event</th><th>Request</th><th>Client</th><th>Call</th><th>Message</th></tr>
    </thead>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td class="nowrap"><?= e(substr((string)$r['timestamp'], 0, 19)) ?></td>
      <td><span class="badge b-<?= e((string)$r['event']) ?>"><?= e((string)$r['event']) ?></span></td>
      <td><?= (int)$r['request_id'] <= 0 ? '-' : (\Admin\View::badge('', 'req#' . (int)$r['request_id'])) ?></td>
      <td><?= (int)$r['client_id'] <= 0 ? '-' : (\Admin\View::badge('', 'cli#' . (int)$r['client_id'])) ?></td>
      <td><?= e((string)$r['call_id']) ?: '-' ?></td>
      <td class="msg"><?= e(Str::truncate((string)($r['message'] ?? ''), 120)) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>
