<?php /** @var array $row @var array $connections */ $isNew = ($row['id'] ?? 0) === 0;
$inst = $row['instant_defaults'] ?? [];
?>
<h1><?= $isNew ? 'New request' : 'Edit request — ' . e((string)$row['code']) ?></h1>
<section class="card slim">
<form method="post" action="/admin/requests" class="form" id="reqform">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
  <div class="grid2">
    <label>Code <small>used in URLs: POST /api/v1/request/{code}</small>
      <input name="code" value="<?= e((string)$row['code']) ?>" required pattern="[A-Za-z0-9._\-]{1,64}">
    </label>
    <label>Name <input name="name" value="<?= e((string)$row['name']) ?>" required></label>
  </div>
  <label>Short description for the data team <textarea name="description" rows="2"><?= e((string)$row['description']) ?></textarea></label>

  <label>Target connection
    <select name="connection_id" required>
      <option value="">— pick —</option>
      <?php foreach ($connections as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= (int)$row['connection_id'] === (int)$c['id'] ? 'selected' : '' ?>>
        <?= e((string)$c['name']) ?> (<?= e((string)$c['db_type']) ?>)<?= $c['is_active'] ? '' : ' · inactive' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label>SQL (reviewed by the data team — clients never send SQL)
    <textarea name="sql_text" rows="8" required spellcheck="false"><?= e((string)$row['sql_text']) ?></textarea>
  </label>

  <div class="grid2">
    <fieldset class="mode">
      <legend>Response mode</legend>
      <label class="chk"><input type="radio" name="response_mode" value="delayed" <?php if (($row['response_mode'] ?? 'delayed') === 'delayed'): ?>checked<?php endif; ?> onchange="modeSwitch('delayed')"> Delayed (worker runs it, JSON file + link + webhook)</label>
      <label class="chk"><input type="radio" name="response_mode" value="instant" <?php if (($row['response_mode'] ?? '') === 'instant'): ?>checked<?php endif; ?> onchange="modeSwitch('instant')"> Instant (synchronous, small + fast queries only)</label>
    </fieldset>
  </div>

  <div id="delayed-fields" class="grid4">
    <label>Delay before run (minutes) <input name="delay_minutes" value="<?= (int)($row['delay_minutes'] ?? 0) ?>" inputmode="numeric"></label>
    <label>TTL for result link (minutes) <input name="ttl_minutes" value="<?= (int)($row['ttl_minutes'] ?? 15) ?>" inputmode="numeric"></label>
  </div>
  <div id="instant-fields" class="grid4">
    <label>Instant cache window (seconds, 0 = off) <input name="instant_cache_seconds" value="<?= (int)($row['instant_cache_seconds'] ?? 0) ?>" inputmode="numeric"></label>
  </div>
  <div class="grid4">
    <label>Query timeout (seconds) <input name="timeout_seconds" value="<?= (int)($row['timeout_seconds'] ?? 60) ?>" inputmode="numeric"></label>
    <label>Max rows <input name="max_rows" value="<?= (int)($row['max_rows'] ?? 100000) ?>" inputmode="numeric"></label>
  </div>
  <div class="form-ops">
    <label class="chk"><input type="checkbox" name="is_active" <?= !empty($row['is_active']) ? 'checked' : '' ?>> Active</label>
    <button type="submit">Save</button>
    <a class="btn" href="/admin/requests">Cancel</a>
  </div>
</form>
</section>
<div class="hint">
Instant mode upper bounds (from config): <?= (int)\Core\Config::get('limits.instant.max_timeout_seconds', 60) ?>s timeout and
<?= number_format((int)\Core\Config::get('limits.instant.max_rows', 20000)) ?> rows. Suggested instant defaults:
<?= (int)($inst['timeout_seconds'] ?? 30) ?>s / <?= number_format((int)($inst['max_rows'] ?? 5000)) ?>.
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
