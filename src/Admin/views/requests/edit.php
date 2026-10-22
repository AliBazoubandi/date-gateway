<?php /** @var array $row @var array $connections @var array $changed @var array<string,string> $errors */
use Admin\View;
$errors = $errors ?? [];
$isNew = ($row['id'] ?? 0) === 0;
$inst = $row['instant_defaults'] ?? [];
$fe = static fn(string $f): string => \Admin\View::ferr($errors, $f);
$fc = static fn(string $f): string => \Admin\View::fcls($errors, $f);
$changed = $changed ?? [];
?>
<link rel="stylesheet" href="/assets/codemirror/codemirror.css">
<div class="page-head">
  <div>
    <h1><?= $isNew ? 'New request' : 'Edit request — ' . e((string)$row['code']) ?></h1>
    <div class="page-sub">Clients call this by code; they never see the SQL.</div>
  </div>
  <div class="page-tools"><a class="btn" href="/admin/requests"><?= View::icon('arrow-start', 14) ?><span>All requests</span></a></div>
</div>

<div class="editor-split">
<form method="post" action="/admin/requests" class="form" id="reqform" style="display:contents">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">

  <!-- ---------------------------------------------------------- editor --- -->
  <div class="ed-left card flat">
    <div class="card-head">
      <div class="card-title"><?= View::icon('terminal', 15) ?><h2>SQL</h2>
        <span class="badge b-key">read-only gate</span></div>
      <div class="card-tools">
        <span class="hint small">single SELECT<?= $isNew ? '' : ' · scripts mode allowed on SQL Server' ?></span>
      </div>
    </div>
    <label class="visually-hidden" for="sql_text">SQL statement</label>
    <textarea name="sql_text" id="sql_text" rows="12" class="<?= $fc('sql_text') ?> sql" data-cm
              spellcheck="false" aria-label="SQL statement"
              placeholder="SELECT  symbol, last_price&#10;FROM    instrument_snapshots&#10;WHERE   traded_at &gt;= :since"><?= e((string)$row['sql_text']) ?></textarea>
    <?= $fe('sql_text') ?>
    <div class="hint" style="margin-block-start:10px">
<?php if ($changed !== []): ?>
      Last changed by <b><?= e($changed['by']) ?></b> at <?= e(substr($changed['at'], 0, 16)) ?> · Tehran time.
<?php else: ?>
      <?= $isNew ? 'New definition — nothing saved yet.' : 'Not modified since creation.' ?>
<?php endif; ?>
    </div>
  </div>

  <!-- -------------------------------------------------------- settings --- -->
  <div class="ed-right">
    <label>Code <small>POST /api/v1/request/{code}</small>
      <input type="text" name="code" value="<?= e((string)$row['code']) ?>" class="<?= $fc('code') ?>" required pattern="[A-Za-z0-9._\-]{1,64}" spellcheck="false">
      <?= $fe('code') ?>
    </label>
    <label>Name
      <input type="text" name="name" value="<?= e((string)$row['name']) ?>" class="<?= $fc('name') ?>" required>
      <?= $fe('name') ?>
    </label>
    <label>Description for the data team
      <textarea name="description" rows="2"><?= e((string)$row['description']) ?></textarea>
    </label>
    <label>Target connection
      <select name="connection_id" class="<?= $fc('connection_id') ?>" required>
        <option value="">— pick —</option>
        <?php foreach ($connections as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)($row['connection_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
          <?= e((string)$c['name']) ?> (<?= e((string)$c['db_type']) ?>)<?= $c['is_active'] ? '' : ' · inactive' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?= $fe('connection_id') ?>
    </label>

    <label>Response mode</label>
    <label class="chk"><input type="radio" name="response_mode" value="delayed" <?php if (($row['response_mode'] ?? 'delayed') === 'delayed'): ?>checked<?php endif; ?> onchange="modeSwitch('delayed')"> Delayed — worker runs it, JSON file + link + webhook</label>
    <label class="chk"><input type="radio" name="response_mode" value="instant" <?php if (($row['response_mode'] ?? '') === 'instant'): ?>checked<?php endif; ?> onchange="modeSwitch('instant')"> Instant — synchronous, small + fast queries only</label>

    <div id="delayed-fields" class="grid2">
      <label>Delay before run (min) <input type="text" name="delay_minutes" value="<?= (int)($row['delay_minutes'] ?? 0) ?>" inputmode="numeric"></label>
      <label>TTL of result link (min) <input type="text" name="ttl_minutes" value="<?= (int)($row['ttl_minutes'] ?? 15) ?>" inputmode="numeric"></label>
    </div>
    <div id="instant-fields" style="display:none">
      <label>Cache window (seconds, 0 = off, max <?= min(86400, \Services\InstantExecutor::resultTtlMinutes() * 60) ?>)
        <input type="text" name="instant_cache_seconds" value="<?= (int)($row['instant_cache_seconds'] ?? 0) ?>" inputmode="numeric">
      </label>
    </div>
    <div class="grid2">
      <label>Timeout (seconds) <input type="text" name="timeout_seconds" value="<?= (int)($row['timeout_seconds'] ?? 60) ?>" inputmode="numeric"></label>
      <label>Max rows <input type="text" name="max_rows" value="<?= (int)($row['max_rows'] ?? 100000) ?>" inputmode="numeric"></label>
    </div>

    <div class="form-ops">
      <label class="chk"><input type="checkbox" name="is_active" <?= !empty($row['is_active']) ? 'checked' : '' ?>> Active</label>
      <div class="between"></div>
      <button class="btn btn-primary" type="submit" form="reqform"><?= View::icon('check', 14) ?><span>Save</span></button>
      <a class="btn" href="/admin/requests">Cancel</a>
    </div>
    <div class="hint small">
      Instant caps from config: <?= (int)\Core\Config::get('limits.instant.max_timeout_seconds', 60) ?>s timeout,
      <?= number_format((int)\Core\Config::get('limits.instant.max_rows', 20000)) ?> rows; suggested defaults
      <?= (int)($inst['timeout_seconds'] ?? 30) ?>s / <?= number_format((int)($inst['max_rows'] ?? 5000)) ?>.
      SQL Server connections with script batches allowed may hold DECLARE/#temp/INSERT scripts; UPDATE, DELETE and EXEC stay blocked everywhere.
    </div>
  </div>
</form>
<?php if (!$isNew): ?>
<div class="tr-dock">
  <form method="post" action="/admin/requests/test">
    <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <button class="btn" type="submit" style="width:100%"><?= View::icon('play', 14) ?><span>Test run — live sample, ≤20 rows</span></button>
  </form>
</div>
<?php endif; ?>
</div>

<script>
function modeSwitch(m) {
  document.getElementById('delayed-fields').style.display = m === 'delayed' ? '' : 'none';
  document.getElementById('instant-fields').style.display = m === 'instant' ? '' : 'none';
}
(function () {
  var m = document.querySelector('input[name=response_mode]:checked');
  if (m) { modeSwitch(m.value); }
})();
</script>
<script src="/assets/codemirror/codemirror.js" defer></script>
<script src="/assets/codemirror/mode-sql.js" defer></script>
<script src="/assets/codemirror/addon-matchbrackets.js" defer></script>
<script src="/assets/codemirror/addon-active-line.js" defer></script>
