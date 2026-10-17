<?php /** @var array $row @var array $requests @var array $granted */ $isNew = ($row['id'] ?? 0) === 0;
?>
<h1><?= $isNew ? 'New client' : 'Edit client — ' . e((string)$row['name']) ?></h1>
<section class="card slim">
<form method="post" action="/admin/clients" class="form">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
  <div class="grid2">
    <label>Name <input name="name" value="<?= e((string)$row['name']) ?>" required></label>
    <label>Type
      <select name="type">
        <option value="agent" <?= ($row['type'] ?? '') === 'agent' ? 'selected' : '' ?>>Agent (calls the API)</option>
        <option value="human" <?= ($row['type'] ?? '') === 'human' ? 'selected' : '' ?>>Human</option>
      </select>
    </label>
  </div>
  <div class="grid2">
    <label>Webhook URL (admin-controlled; empty = no webhooks)
      <input name="webhook_url" value="<?= e((string)$row['webhook_url']) ?>" placeholder="https://client-systems.example/hook/gw">
    </label>
    <label>Webhook secret (HMAC-SHA256 key the client verifies)
      <input name="webhook_secret" value="<?= e((string)$row['webhook_secret']) ?>" placeholder="reveal/rotate below">
    </label>
  </div>
  <div class="form-ops">
    <label class="chk"><input type="checkbox" name="is_active" <?= !empty($row['is_active']) ? 'checked' : '' ?>> Active</label>
    <button type="submit">Save</button>
    <a class="btn" href="/admin/clients">Cancel</a>
  </div>
</form>
<?php if (!$isNew): ?>
<div class="grid2" style="margin-top:10px">
  <form method="post" action="/admin/clients/regen-key" class="inline">
    <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <button class="btn" onclick="return confirm('Regenerate API key? The old key stops working immediately.')">Regenerate API key</button>
  </form>
  <form method="post" action="/admin/clients/gen-secret" class="inline">
    <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <button class="btn">Generate new webhook secret</button>
  </form>
</div>
<?php endif; ?>
</section>

<?php if (!$isNew): ?>
<section class="card">
  <h2>Permissions — which requests may this client call?</h2>
  <form method="post" action="/admin/clients/permissions" class="form">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <table class="rows">
      <tr><th></th><th>Code</th><th>Name</th><th>Mode</th></tr>
      <?php foreach ($requests as $r): $on = !empty($granted[(int)$r['id']]); ?>
      <tr>
        <td><input type="checkbox" name="request_ids[]" value="<?= (int)$r['id'] ?>" <?= $on ? 'checked' : '' ?>></td>
        <td><strong><?= e((string)$r['code']) ?></strong></td>
        <td><?= e((string)$r['name']) ?></td>
        <td><?= e((string)$r['response_mode']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <button type="submit">Save permissions</button>
  </form>
</section>
<?php else: ?>
<p class="hint">Save the new client first; then grant permissions on this page.</p>
<?php endif; ?>
