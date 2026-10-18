<?php /** @var array $row|null */
$m = static fn(string $k, $default = ''): mixed => $row[$k] ?? $default;
$isNew = ($m('id', 0) == 0);
?>
<h1><?= $isNew ? 'New connection' : 'Edit connection — ' . e((string)$m('name')) ?></h1>
<section class="card slim">
<form method="post" action="/admin/connections" class="form grid2">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int)$m('id', 0) ?>">
  <label>Name <input name="name" value="<?= e((string)$m('name')) ?>" required></label>
  <label>Database type
    <select name="db_type">
      <?php foreach (['sqlserver' => 'SQL Server (sqlsrv)', 'pgsql' => 'PostgreSQL (pg)', 'mysql' => 'MySQL/MariaDB (mysqli)'] as $t => $label): ?>
      <option value="<?= e($t) ?>" <?= $m('db_type') === $t ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Host <input name="host" value="<?= e((string)$m('host')) ?>" required placeholder="host or instance"></label>
  <label>Port <input name="port" value="<?= $m('port') === null ? '' : (int)$m('port') ?>" placeholder="default"></label>
  <label>Database name <input name="db_name" value="<?= e((string)$m('db_name')) ?>" required></label>
  <label>Username <input name="username" value="<?= e((string)$m('username')) ?>" required></label>
  <label>Password
    <input type="password" name="password" placeholder="<?= $isNew ? 'required' : 'leave empty to keep current' ?>">
  </label>
  <label>Max concurrent runs
    <input name="max_concurrency" value="<?= $m('max_concurrency') === null ? '' : (int)$m('max_concurrency') ?>"
           placeholder="default (2)" inputmode="numeric">
  </label>
  <label class="chk"><input type="checkbox" name="is_active" <?= $m('is_active') ? 'checked' : '' ?>> Active</label>
  <div class="form-ops">
    <button type="submit">Save</button>
    <a class="btn" href="/admin/connections">Cancel</a>
  </div>
</form>
</section>
<p class="hint">Passwords are AES-256-GCM encrypted with the master key in config/config.php, never shown again.</p>
