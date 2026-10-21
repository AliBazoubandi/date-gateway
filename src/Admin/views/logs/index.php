<?php /** @var array $rows @var array $f @var array $events @var array $clients
  @var array $requests @var int $total @var int $page @var int $pages @var string $qs */
use Admin\View;
use Core\Str;
?>
<div class="page-head">
  <div>
    <h1>Request logs</h1>
    <div class="page-sub">Append-only trail of every call, run and webhook.</div>
  </div>
  <div class="page-tools"><span class="badge b-info"><?= number_format($total) ?> rows total</span></div>
</div>

<div class="tabs" role="tablist" id="logs-tabs">
  <button class="tab on" role="tab" data-tab="tail" aria-selected="true"><?= View::icon('terminal', 15) ?>Live tail</button>
  <button class="tab" role="tab" data-tab="search" aria-selected="false"><?= View::icon('search', 15) ?>Search &amp; filter</button>
</div>

<!-- =============================== LIVE TAIL ================================ -->
<section id="logs-pane-tail" role="tabpanel">
  <div class="term">
    <div class="term-head">
      <span class="term-dot" id="term-dot" aria-hidden="true"></span>
      <span class="term-title">request_logs</span>
      <div class="term-spacer"></div>
      <div class="chips">
        <select id="tail-client" class="chip" style="width:auto;padding-block:3px" aria-label="Filter by client">
          <option value="">all clients</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></option><?php endforeach; ?>
        </select>
        <select id="tail-event" class="chip" style="width:auto;padding-block:3px" aria-label="Filter by event">
          <option value="">all events</option>
          <?php foreach ($events as $ev): ?><option value="<?= e($ev) ?>"><?= e($ev) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button type="button" class="btn btn-sm" id="tail-pause" title="Space pauses/resumes while the tail is focused" aria-pressed="false" data-pause-base="/admin/api/tail?">
        <?= View::icon('pause', 13) ?><span>Pause</span>
      </button>
    </div>
    <div class="term-body" id="term-body" tabindex="0" aria-live="polite" aria-label="Live log rows">
      <div class="skel skel-table-row"></div>
      <div class="skel skel-table-row"></div>
      <div class="skel skel-table-row"></div>
      <div class="skel skel-table-row"></div>
    </div>
  </div>
  <p class="hint small" style="margin-top:8px">New rows land automatically (every ~3s); click a row with a call id to open the inspector.</p>
</section>

<!-- ============================ SEARCH & FILTER ============================= -->
<section id="logs-pane-search" role="tabpanel" hidden>
  <form method="get" action="/admin/logs" class="form filters">
    <label>Client
      <select name="client_id">
        <option value="">— all —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$f['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Request
      <select name="request_id">
        <option value="">— all —</option>
        <?php foreach ($requests as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= (int)$f['request_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e((string)$r['code']) ?></option>
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
    <div class="f-submit">
      <button type="submit" class="btn"><?= View::icon('filter', 13) ?><span>Apply</span></button>
      <a class="btn btn-ghost btn-sm" href="/admin/logs">Reset</a>
    </div>
  </form>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><h2>Results</h2></div>
      <div class="card-tools"><span class="hint small">page <?= (int)$page ?> / <?= (int)$pages ?> · newest first · append-only, never deleted</span></div>
    </div>
    <div class="table-scroll">
      <table class="rows">
        <thead><tr>
          <th>Time</th><th>Event</th><th>Request</th><th>Mode</th><th>Client</th>
          <th>Call</th><th>Exec</th><th>IP</th><th class="num">ms</th><th class="num">Rows</th><th>Message</th>
        </tr></thead>
        <tbody>
<?php if ($rows === []): ?>
        <tr><td colspan="11"><div class="empty" style="border:0;padding:22px"><?= View::icon('search', 26) ?><b>No log rows match these filters</b><span>Widen the window or reset the filters above.</span></div></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): $cid = (string)($r['call_id'] ?? ''); ?>
        <tr data-call="<?= e($cid) ?>" class="<?= $cid !== '' ? 'row-openable' : '' ?>" title="<?= $cid !== '' ? 'Open inspector' : '' ?>">
          <td class="nowrap mono"><?= e(substr((string)$r['timestamp'], 0, 19)) ?></td>
          <td><?= Admin\View::badge((string)$r['event'], (string)$r['event']) ?></td>
          <td class="nowrap"><?= $r['request_code'] !== null ? '<strong>' . e((string)$r['request_code']) . '</strong>' : '<span class="hint">-</span>' ?></td>
          <td><?= $r['mode'] !== null ? e((string)$r['mode']) : '-' ?></td>
          <td><?= $r['client_name'] !== null ? e((string)$r['client_name']) : '-' ?></td>
          <td class="msg" title="<?= e($cid) ?>"><?= $cid !== '' ? '<code>' . e(substr($cid, 0, 10)) . '…</code>' : '-' ?></td>
          <td><?= $r['execution_id'] !== null ? '#' . (int)$r['execution_id'] : '-' ?></td>
          <td class="msg"><?= $r['ip'] !== null && $r['ip'] !== '' ? e((string)$r['ip']) : '-' ?></td>
          <td class="num"><?= $r['duration_ms'] !== null ? (int)$r['duration_ms'] : '-' ?></td>
          <td class="num"><?= $r['row_count'] !== null ? number_format((int)$r['row_count']) : '-' ?></td>
          <td class="msg"><?= $r['message'] !== null ? e((string)$r['message']) : '-' ?></td>
        </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php if ($pages > 1): ?>
    <div class="form-ops pager">
      <?php if ($page > 1): ?>
        <a class="btn sm" href="/admin/logs?<?= e($qs) ?>page=<?= $page - 1 ?>"><?= View::icon('arrow-start', 13) ?><span>Newer</span></a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a class="btn sm" href="/admin/logs?<?= e($qs) ?>page=<?= $page + 1 ?>"><span>Older</span><?= View::icon('arrow-end', 13) ?></a>
      <?php endif; ?>
      <span class="hint">page <?= (int)$page ?> of <?= (int)$pages ?></span>
    </div>
<?php endif; ?>
  </section>
</section>
