<?php /** @var array $counts @var array $recent */
use Core\Str;
$c = $counts['programs'] ?? [];
?>
<h1>Dashboard</h1>
<section class="cols">
  <div class="card">
    <h2>Catalog</h2>
    <table class="meta">
      <tr><td>Requests</td><td><?= (int)$counts['requests_active'] ?> active / <?= (int)$counts['requests'] ?> total</td></tr>
      <tr><td>Connections</td><td><?= (int)$counts['connections'] ?></td></tr>
      <tr><td>Clients</td><td><?= (int)$counts['clients'] ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Executions &amp; traffic</h2>
    <table class="meta">
      <tr><td>Scheduled now</td><td><?= (int)($c['scheduled'] ?? 0) ?></td></tr>
      <tr><td>Running now</td><td><?= (int)($c['running'] ?? 0) ?></td></tr>
      <tr><td>Ready now</td><td><?= (int)($c['ready'] ?? 0) ?></td></tr>
      <tr><td>Failed (last 24h)</td><td><?= (int)($c['failed_24h'] ?? 0) ?></td></tr>
      <tr><td>Calls (last 24h)</td><td><?= (int)($c['calls_24h'] ?? 0) ?></td></tr>
    </table>
  </div>
</section>
<section class="card">
  <h2>Latest log entries</h2>
  <?php if ($recent === []): ?><p>No log entries yet.</p><?php else: ?>
  <table class="rows">
    <tr><th>When</th><th>Event</th><th>Request</th><th>Client</th><th>Call</th><th>Message</th></tr>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td><?= e(substr((string)$r['timestamp'], 0, 19)) ?></td>
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
