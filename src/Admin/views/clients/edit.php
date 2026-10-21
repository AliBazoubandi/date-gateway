<?php /** @var array $row @var array $requests @var array $granted @var array<string,string> $errors */
use Admin\View;
$errors = $errors ?? [];
$isNew = ($row['id'] ?? 0) === 0;
$fe = static fn(string $f): string => \Admin\View::ferr($errors, $f);
$fc = static fn(string $f): string => \Admin\View::fcls($errors, $f);
?>
<div class="page-head">
  <div>
    <h1><?= $isNew ? 'New client' : 'Edit client — ' . e((string)$row['name']) ?></h1>
    <div class="page-sub">Agents authenticate with an API key; humans get a portal token.</div>
  </div>
  <div class="page-tools"><a class="btn" href="/admin/clients"><?= View::icon('arrow-start', 14) ?><span>All clients</span></a></div>
</div>

<div class="tabs" id="client-tabs" role="tablist"<?= $isNew ? ' hidden' : '' ?>>
  <button class="tab on" role="tab" data-tab="settings" aria-selected="true">Settings</button>
  <button class="tab" role="tab" data-tab="perms" aria-selected="false">Permissions <span class="count"><?= count($requests) ?></span></button>
</div>

<div id="client-pane-settings" role="tabpanel">
<section class="card slim form">
<form method="post" action="/admin/clients">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
  <div class="grid2">
    <label>Name <input name="name" value="<?= e((string)$row['name']) ?>" class="<?= $fc('name') ?>" required><?= $fe('name') ?></label>
    <label>Type
      <select name="type">
        <option value="agent" <?= ($row['type'] ?? '') === 'agent' ? 'selected' : '' ?>>Agent (calls the API)</option>
        <option value="human" <?= ($row['type'] ?? '') === 'human' ? 'selected' : '' ?>>Human</option>
      </select>
    </label>
  </div>
  <div class="grid2">
    <label>Webhook URL (admin-controlled; empty = no webhooks)
      <input name="webhook_url" value="<?= e((string)$row['webhook_url']) ?>" class="<?= $fc('webhook_url') ?>" placeholder="https://client-systems.example/hook/gw">
      <?= $fe('webhook_url') ?>
    </label>
    <label>Webhook secret (HMAC-SHA256 key the client verifies)
      <input name="webhook_secret" value="" class="<?= $fc('webhook_secret') ?>" placeholder="encrypted at rest — leave empty to keep" autocomplete="new-password">
      <?= $fe('webhook_secret') ?>
    </label>
  </div>
  <?php if (!$isNew): ?>
  <p class="hint">The stored secret is kept encrypted and is never shown. Leave the field empty to keep it; rotate with the button below — the new secret is then shown exactly once.</p>
  <?php endif; ?>
  <div class="form-ops">
    <label class="chk"><input type="checkbox" name="is_active" <?= !empty($row['is_active']) ? 'checked' : '' ?>> Active</label>
    <div class="between"></div>
    <button class="btn btn-primary" type="submit"><?= View::icon('check', 14) ?><span>Save</span></button>
    <a class="btn" href="/admin/clients">Cancel</a>
  </div>
</form>
<?php if (!$isNew): ?>
<div class="grid2" style="margin-top:14px">
  <form method="post" action="/admin/clients/regen-key" data-confirm="Regenerate the API key of “<?= e((string)$row['name']) ?>”? The old key stops working immediately.">
    <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <button class="btn" type="submit"><?= View::icon('key', 14) ?><span>Regenerate API key</span></button>
  </form>
  <form method="post" action="/admin/clients/gen-secret" data-confirm="Generate a new webhook secret? The current one stops verifying.">
    <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <button class="btn" type="submit"><?= View::icon('send', 14) ?><span>Generate new webhook secret</span></button>
  </form>
</div>
<?php endif; ?>
</section>
</div>

<?php if (!$isNew): ?>
<div id="client-pane-perms" role="tabpanel" hidden>
  <section class="card">
    <form method="post" action="/admin/clients/permissions">
      <?= $csrf ?>
      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
      <div class="checklist-tools">
        <div class="check-search">
          <?= View::icon('search', 15) ?>
          <input type="search" id="perm-filter" placeholder="Filter by code or name…" aria-label="Filter permissions" autocomplete="off" spellcheck="false">
        </div>
        <div class="inline">
          <label class="chk" style="margin:0"><input type="checkbox" id="perm-all"> <span>Select all shown</span></label>
          <span class="count" id="perm-count"><?= count($requests) ?></span>
          <button class="btn btn-primary" type="submit"><?= View::icon('check', 14) ?><span>Save permissions</span></button>
        </div>
      </div>
      <div class="checklist" id="perm-list">
        <?php foreach ($requests as $r): $on = !empty($granted[(int)$r['id']]); ?>
        <label class="chk perm-row" data-search="<?= e(strtolower((string)$r['code'] . ' ' . (string)$r['name'])) ?>">
          <input type="checkbox" name="request_ids[]" value="<?= (int)$r['id'] ?>" <?= $on ? 'checked' : '' ?>>
          <b><?= e((string)$r['code']) ?></b>
          <small><?= e((string)$r['name']) ?></small>
          <span style="margin-inline-start:auto"><?= $r['response_mode'] === 'instant' ? '<span class="badge b-mode instant">Instant</span>' : '<span class="badge b-mode delayed">' . View::icon('clock', 11) . 'Delayed</span>' ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($requests === []): ?>
      <div class="empty" style="margin-top:10px"><b>No requests to grant</b><span>Create requests first, then come back to grant this client.</span></div>
      <?php endif; ?>
    </form>
  </section>
</div>
<?php else: ?>
<div id="client-pane-perms" hidden></div>
<p class="hint">Save the new client first; then grant permissions on this page.</p>
<?php endif; ?>
