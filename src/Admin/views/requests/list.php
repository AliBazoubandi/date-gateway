<?php /** @var array $rows @var array $sparks @var array $f @var array $connections @var array $clients @var ?array $grantReq @var array $grantedClientIds */
use Admin\View;
use Core\Str;

/* duration sparkline: index 0 = newest in $sparks; drawn oldest -> newest */
$sparkSvg = static function (array $vals, string $color = ''): string {
    if (count($vals) < 2) {
        return '';
    }
    $pts = array_reverse($vals);            // oldest first for drawing
    $w = 100;
    $h = 24;
    $max = max(1, max($pts));
    $step = $w / (count($pts) - 1);
    $xy = [];
    foreach ($pts as $i => $n) {
        $xy[] = round($i * $step, 1) . ',' . round($h - 3 - ($h - 7) * ($n / $max), 1);
    }
    $line = implode(' ', $xy);
    $last = $xy[count($xy) - 1];
    return '<svg class="spark ' . $color . '" viewBox="0 0 ' . $h * 4 . ' ' . $h
        . '" preserveAspectRatio="none" style="height:22px" aria-hidden="true">'
        . '<polygon class="area" points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '"/>'
        . '<polyline points="' . $line . '"/></svg>';
};

$modeBadge = static function (string $mode): string {
    return $mode === 'instant'
        ? '<span class="badge b-mode instant">' . View::icon('zap', 11) . 'Instant</span>'
        : '<span class="badge b-mode delayed">' . View::icon('clock', 11) . 'Delayed</span>';
};
$stateBadge = static function (int $active): string {
    return $active === 1
        ? '<span class="badge b-ok">' . View::icon('check', 11) . 'Active</span>'
        : '<span class="badge b-warn">Off</span>';
};
?>
<div class="page-head">
  <div>
    <h1>Requests</h1>
    <div class="page-sub">Everything clients can call — <?= count($rows) ?> definition<?= count($rows) === 1 ? '' : 's' ?>.</div>
  </div>
  <div class="page-tools">
    <div class="seg" role="group" aria-label="List presentation" id="view-toggle">
      <button type="button" data-view="cards"><?= View::icon('grid', 14) ?><span>Cards</span></button>
      <button type="button" data-view="table" class="on"><?= View::icon('list', 14) ?><span>Table</span></button>
    </div>
    <a class="btn btn-primary" href="/admin/requests/new"><?= View::icon('plus', 15) ?><span>New request</span></a>
  </div>
</div>

<form method="get" action="/admin/requests" class="form filters">
  <label class="f-grow">Search
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Code or name…" spellcheck="false">
  </label>
  <label>Mode
    <select name="mode">
      <option value="">— all —</option>
      <option value="delayed" <?= $f['mode'] === 'delayed' ? 'selected' : '' ?>>Delayed</option>
      <option value="instant" <?= $f['mode'] === 'instant' ? 'selected' : '' ?>>Instant</option>
    </select>
  </label>
  <label>Connection
    <select name="connection">
      <option value="">— all —</option>
      <?php foreach ($connections as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $f['conn'] === (string)(int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>State
    <select name="active">
      <option value="">— all —</option>
      <option value="1" <?= $f['active'] === '1' ? 'selected' : '' ?>>Active</option>
      <option value="0" <?= $f['active'] === '0' ? 'selected' : '' ?>>Off</option>
    </select>
  </label>
  <div class="f-submit">
    <button class="btn" type="submit"><?= View::icon('filter', 13) ?><span>Apply</span></button>
    <a class="btn btn-ghost btn-sm" href="/admin/requests">Clear</a>
  </div>
</form>

<?php if ($rows === []): ?>
<section class="card">
  <div class="empty">
    <?= View::icon('file', 34) ?>
    <b>No requests<?= ($f['q'] !== '' || $f['mode'] !== '' || $f['conn'] !== '' || $f['active'] !== '') ? ' match these filters' : ' yet' ?></b>
    <span>A request bundles a read-only SQL statement, a connection and response settings. Create the first one, then grant it to clients.</span>
    <a class="btn btn-primary" href="/admin/requests/new"><?= View::icon('plus', 14) ?><span>New request</span></a>
  </div>
</section>
<?php else: ?>

<!-- =============== cards view (JSdefault toggle; no-JS keeps the table) === -->
<div id="req-cards" class="req-grid" hidden>
<?php foreach ($rows as $r): $sp = $sparks[(int)$r['id']] ?? []; ?>
  <article class="req-card<?= (int)$r['is_active'] === 0 ? ' off' : '' ?>">
    <div class="req-code-row">
      <a class="req-code" href="/admin/requests/<?= (int)$r['id'] ?>"><?= e((string)$r['code']) ?></a>
      <?= $stateBadge((int)$r['is_active']) ?>
    </div>
    <span class="req-name"><?= e((string)$r['name']) ?></span>
    <div class="req-meta">
      <?= $modeBadge((string)$r['response_mode']) ?>
      <span class="req-conn"><?= View::icon('database', 12) ?><?= e((string)$r['connection_name']) ?></span>
      <span class="badge"><?= (int)$r['runs_total'] ?> runs</span>
    </div>
    <?php if ($sp !== []): ?><?= $sparkSvg($sp) ?><?php endif; ?>
    <div class="req-foot">
      <span class="hint small">updated <?= e(substr((string)($r['updated_at'] ?? ''), 0, 16)) ?></span>
      <div class="ops">
        <a class="btn btn-sm" href="/admin/requests?grant_permissions=<?= (int)$r['id'] ?>" title="Manage client permissions"><?= View::icon('shield', 13) ?> Permissions</a>
        <a class="btn btn-sm" href="/admin/requests/<?= (int)$r['id'] ?>">Edit</a>
        <form method="post" action="/admin/requests/delete" data-confirm="Delete request «<?= e((string)$r['code']) ?>»? This will permanently remove the request." class="inline">
          <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm danger" type="submit"><?= View::icon('trash', 12) ?> Delete</button>
        </form>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>

<!-- ============================ table view ================================= -->
<section class="card" id="req-table">
  <div class="table-scroll" style="max-height:none">
    <table class="rows sortable">
      <thead>
        <tr>
          <th>Code</th>
          <th>Name</th>
          <th>Connection</th>
          <th>Mode</th>
          <th class="num">Runs</th>
          <th>Durations · 7d</th>
          <th>State</th>
          <th class="ops">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $sp = $sparks[(int)$r['id']] ?? []; ?>
        <tr>
          <td class="nowrap"><a href="/admin/requests/<?= (int)$r['id'] ?>"><code><?= e((string)$r['code']) ?></code></a></td>
          <td class="nowrap cell-name"><?= e((string)$r['name']) ?></td>
          <td class="nowrap"><span class="req-conn"><?= View::icon('database', 12) ?><?= e((string)$r['connection_name']) ?></span></td>
          <td class="nowrap"><?= $modeBadge((string)$r['response_mode']) ?></td>
          <td class="num nowrap"><?= number_format((int)$r['runs_total']) ?></td>
          <td class="nowrap"><?= $sp !== [] ? $sparkSvg($sp) : '<span class="hint small">—</span>' ?></td>
          <td class="nowrap"><?= $stateBadge((int)$r['is_active']) ?></td>
          <td class="ops nowrap">
            <a class="btn btn-sm" href="/admin/requests?grant_permissions=<?= (int)$r['id'] ?>" title="Manage client permissions"><?= View::icon('shield', 13) ?> Permissions</a>
            <a class="btn btn-sm" href="/admin/requests/<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" action="/admin/requests/delete" data-confirm="Delete request «<?= e((string)$r['code']) ?>»? This will permanently remove the request." class="inline">
              <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm danger" type="submit"><?= View::icon('trash', 12) ?> Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($grantReq !== null): ?>
<div class="modal" id="perm-modal" role="dialog" aria-modal="true" aria-labelledby="perm-modal-title">
  <div class="modal-box modal-lg">
    <div class="modal-head-bar">
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span class="badge b-key"><?= View::icon('shield', 12) ?> Permissions</span>
          <code><?= e((string)$grantReq['code']) ?></code>
        </div>
        <h3 id="perm-modal-title" style="margin:0 0 4px">Grant Client Permissions</h3>
        <p class="hint small" style="margin:0">Select all clients allowed to run <b><?= e((string)$grantReq['name']) ?></b>.</p>
      </div>
      <a href="/admin/requests" class="icon-btn" title="Close" aria-label="Close"><?= View::icon('x', 16) ?></a>
    </div>

    <form method="post" action="/admin/requests/permissions" class="perm-modal-form">
      <?= $csrf ?>
      <input type="hidden" name="id" value="<?= (int)$grantReq['id'] ?>">

      <div class="checklist-tools">
        <div class="check-search">
          <?= View::icon('search', 15) ?>
          <input type="search" id="modal-perm-filter" placeholder="Filter clients by name or type…" aria-label="Filter clients" autocomplete="off" spellcheck="false">
        </div>
        <div class="inline">
          <label class="chk" style="margin:0"><input type="checkbox" id="modal-perm-all"> <span>Select all shown</span></label>
          <span class="count" id="modal-perm-count"><?= count($clients) ?> clients</span>
        </div>
      </div>

      <div class="checklist perm-modal-list" id="modal-perm-list">
        <?php foreach ($clients as $c): 
          $cid = (int)$c['id'];
          $checked = in_array($cid, $grantedClientIds, true);
        ?>
        <label class="chk perm-row" data-search="<?= e(strtolower((string)$c['name'] . ' ' . (string)$c['type'])) ?>">
          <input type="checkbox" name="client_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
          <div class="perm-client-info">
            <b><?= e((string)$c['name']) ?></b>
            <span class="badge badge-sm <?= $c['type'] === 'agent' ? 'b-info' : 'b-key' ?>"><?= e((string)$c['type']) ?></span>
          </div>
          <span class="hint small perm-status" style="margin-inline-start:auto">
            <?= $checked ? '<span class="granted-tag">' . View::icon('check', 11) . ' Granted</span>' : 'No access' ?>
          </span>
        </label>
        <?php endforeach; ?>
        <?php if ($clients === []): ?>
        <div class="empty" style="margin:20px 0">
          <b>No active clients</b>
          <span>Create an active client first in <a href="/admin/clients">Clients</a>.</span>
        </div>
        <?php endif; ?>
      </div>

      <div class="m-actions" style="margin-top:16px;display:flex;justify-content:space-between;align-items:center">
        <a href="/admin/requests" class="btn">Cancel / Skip</a>
        <button type="submit" class="btn btn-primary"><?= View::icon('check', 14) ?> Save Permissions</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
