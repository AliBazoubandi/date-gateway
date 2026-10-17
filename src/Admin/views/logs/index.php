<?php /** @var array $rows @var array $f @var array $events @var array $clients
  @var array $requests @var int $total @var int $page @var int $pages @var string $qs */
?>
<h1>Request logs</h1>
<section class="card">
  <form method="get" action="/admin/logs" class="form filters">
    <label>Client
      <select name="client_id">
        <option value="">— all —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$f['client_id'] === (int)$c['id'] ? 'selected' : '' ?>>
          <?= e((string)$c['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Request
      <select name="request_id">
        <option value="">— all —</option>
        <?php foreach ($requests as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= (int)$f['request_id'] === (int)$r['id'] ? 'selected' : '' ?>>
          <?= e((string)$r['code']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Event
      <select name="event">
        <option value="">— all —</option>
        <?php foreach ($events as $ev): ?>
        <option value="<?= e($ev) ?>" <?= $f['event'] === $ev ? 'selected' : '' ?>><?= e($ev) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Mode
      <select name="mode">
        <option value="">— all —</option>
        <option value="delayed" <?= $f['mode'] === 'delayed' ? 'selected' : '' ?>>Delayed</option>
        <option value="instant" <?= $f['mode'] === 'instant' ? 'selected' : '' ?>>Instant</option>
      </select>
    </label>
    <label>From <input type="date" name="date_from" value="<?= e($f['date_from']) ?>"></label>
    <label>To <input type="date" name="date_to" value="<?= e($f['date_to']) ?>"></label>
    <div class="form-ops">
      <button type="submit">Filter</button>
      <a class="btn" href="/admin/logs">Reset</a>
    </div>
  </form>
</section>

<section class="card">
  <p class="hint"><?= number_format($total) ?> log row<?= $total === 1 ? '' : 's' ?>
    · page <?= (int)$page ?>/<?= (int)$pages ?> · newest first · append-only, never deleted</p>
  <table class="rows">
    <tr>
      <th>Time</th><th>Event</th><th>Request</th><th>Mode</th><th>Client</th>
      <th>Call</th><th>Exec</th><th>IP</th><th>ms</th><th>Rows</th><th>Message</th>
    </tr>
<?php if ($rows === []): ?>
    <tr><td colspan="11" class="hint">No log rows match these filters.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr>
    <td class="nowrap"><?= e(substr((string)$r['timestamp'], 0, 19)) ?></td>
    <td><?= Admin\View::badge((string)$r['event'], (string)$r['event']) ?></td>
    <td><?= $r['request_code'] !== null ? '<strong>' . e((string)$r['request_code']) . '</strong>' : '<span class="hint">-</span>' ?></td>
    <td><?= $r['mode'] !== null ? e((string)$r['mode']) : '-' ?></td>
    <td><?= $r['client_name'] !== null ? e((string)$r['client_name']) : '-' ?></td>
    <td class="msg" title="<?= e((string)($r['call_id'] ?? '')) ?>"><?php
      $cid = (string)($r['call_id'] ?? '');
      echo $cid !== '' ? '<code>' . e(substr($cid, 0, 10)) . '…</code>' : '-';
    ?></td>
    <td><?= $r['execution_id'] !== null ? '#' . (int)$r['execution_id'] : '-' ?></td>
    <td class="msg"><?= $r['ip'] !== null && $r['ip'] !== '' ? e((string)$r['ip']) : '-' ?></td>
    <td><?= $r['duration_ms'] !== null ? (int)$r['duration_ms'] : '-' ?></td>
    <td><?= $r['row_count'] !== null ? number_format((int)$r['row_count']) : '-' ?></td>
    <td class="msg"><?= $r['message'] !== null ? e((string)$r['message']) : '-' ?></td>
  </tr>
<?php endforeach; ?>
  </table>
<?php if ($pages > 1): ?>
  <div class="form-ops pager">
    <?php if ($page > 1): ?>
      <a class="btn sm" href="/admin/logs?<?= e($qs) ?>page=<?= $page - 1 ?>">&larr; Newer</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
      <a class="btn sm" href="/admin/logs?<?= e($qs) ?>page=<?= $page + 1 ?>">Older &rarr;</a>
    <?php endif; ?>
    <span class="hint">page <?= (int)$page ?> of <?= (int)$pages ?></span>
  </div>
<?php endif; ?>
</section>
