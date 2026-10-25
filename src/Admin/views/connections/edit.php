<?php /** @var array|null $row @var array<string,string> $errors */
$row = $row ?? [];
$errors = $errors ?? [];
$m = static fn(string $k, $default = ''): mixed => $row[$k] ?? $default;
$isNew = ($m('id', 0) == 0);
$fe = static fn(string $f): string => \Admin\View::ferr($errors, $f);
$fc = static fn(string $f): string => \Admin\View::fcls($errors, $f);
?>
<div class="page-head">
  <div>
    <h1><?= $isNew ? 'New connection' : 'Edit connection — ' . e((string)$m('name')) ?></h1>
    <div class="page-sub">Credentials stay AES-256-GCM encrypted at rest.</div>
  </div>
  <div class="page-tools">
    <?php if (!$isNew): ?>
    <form method="post" action="/admin/connections/delete" data-confirm="Delete connection “<?= e((string)$m('name')) ?>”? Its stored credentials will be removed." class="inline" style="margin:0">
      <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$m('id') ?>">
      <button class="btn danger" type="submit"><?= \Admin\View::icon('trash', 14) ?><span>Delete</span></button>
    </form>
    <?php endif; ?>
    <a class="btn" href="/admin/connections"><?= \Admin\View::icon('arrow-start', 14) ?><span>All connections</span></a>
  </div>
</div>
<section class="card slim">
<form method="post" action="/admin/connections" class="form grid2">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int)$m('id', 0) ?>">
  <label>Name <input type="text" name="name" value="<?= e((string)$m('name')) ?>" class="<?= $fc('name') ?>" required></label>
  <label>Database type
    <select name="db_type" class="<?= $fc('db_type') ?>">
      <?php foreach (['sqlserver' => 'SQL Server (sqlsrv)', 'pgsql' => 'PostgreSQL (pg)', 'mysql' => 'MySQL/MariaDB (mysqli)'] as $t => $label): ?>
      <option value="<?= e($t) ?>" <?= $m('db_type') === $t ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?= $fe('db_type') ?>
  </label>
  <label>Host <input type="text" name="host" value="<?= e((string)$m('host')) ?>" class="<?= $fc('host') ?>" required placeholder="host or instance"><?= $fe('host') ?></label>
  <label>Port <input type="text" name="port" value="<?= $m('port') === null ? '' : (int)$m('port') ?>" class="<?= $fc('port') ?>" placeholder="default"><?= $fe('port') ?></label>
  <label>Database name <input type="text" name="db_name" value="<?= e((string)$m('db_name')) ?>" class="<?= $fc('db_name') ?>" required><?= $fe('db_name') ?></label>
  <label>Username <input type="text" name="username" value="<?= e((string)$m('username')) ?>" class="<?= $fc('username') ?>" required><?= $fe('username') ?></label>
  <label>Password
    <input type="password" name="password" placeholder="<?= $isNew ? 'required' : 'leave empty to keep current' ?>">
  </label>
  <label>Max concurrent runs
    <input type="text" name="max_concurrency" value="<?= $m('max_concurrency') === null ? '' : (int)$m('max_concurrency') ?>"
           class="<?= $fc('max_concurrency') ?>" placeholder="default (2)" inputmode="numeric">
    <?= $fe('max_concurrency') ?>
  </label>
  <label class="chk"><input type="checkbox" name="allow_writes" <?= $m('allow_writes') ? 'checked' : '' ?>> Allow script batches (SQL Server only)</label>
  <label class="chk"><input type="checkbox" name="is_active" <?= $m('is_active') ? 'checked' : '' ?>> Active</label>
  <?= $fe('allow_writes') ?>
  <div class="form-ops">
    <button class="btn btn-primary" type="submit"><?= \Admin\View::icon('check', 14) ?><span>Save</span></button>
    <a class="btn" href="/admin/connections">Cancel</a>
  </div>
</form>
</section>
<p class="hint">Passwords are AES-256-GCM encrypted with the master key (GATEWAY_MASTER_KEY in config/.env), never shown again.
"Allow script batches" lets requests on this connection run multi-statement scripts (DECLARE, #temp tables, INSERT, CREATE/DROP + a final SELECT) under the relaxed SQL policy — UPDATE and DELETE statements stay blocked. SQL Server connections only.</p>
