<?php /** @var array $rows @var int $me */
use Admin\View;
?>
<div class="page-head">
  <div>
    <h1>Admin Users</h1>
    <div class="page-sub">Accounts that can open this panel. Password hashing never leaves PHP — PHPASS-style bcrypt, same as the CLI.</div>
  </div>
</div>

<section class="card slim">
  <div class="card-head">
    <div class="card-title"><?= View::icon('plus', 15) ?><h2>Add admin</h2></div>
    <div class="card-tools"><span class="hint small">also creatable via <code>php cli\create_admin.php</code></span></div>
  </div>
  <form method="post" action="/admin/admins" class="form">
    <?= $csrf ?>
    <div class="inline" style="align-items:end">
      <label style="flex:1;min-width:200px">Username
        <input name="username" required pattern="[A-Za-z0-9._\-]{3,64}" autocomplete="username" spellcheck="false"
               placeholder="ops.alireza">
      </label>
      <label style="flex:1;min-width:200px">Password
        <input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="10+ characters">
      </label>
      <button class="btn btn-primary" type="submit"><?= View::icon('plus', 14) ?><span>Create</span></button>
    </div>
  </form>
</section>

<section class="card">
  <div class="table-scroll" style="max-height:none">
    <table class="rows">
      <thead><tr>
        <th>Admin</th><th>State</th><th class="num">Lockouts</th><th>Last login</th><th>Created</th><th class="ops">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $active = (int)$r['is_active'] === 1; ?>
        <tr>
          <td>
            <div class="inline" style="flex-wrap:nowrap">
              <span class="who-av" aria-hidden="true"><?= e(strtoupper(substr((string)$r['username'], 0, 2))) ?></span>
              <span><b><?= e((string)$r['username']) ?></b>
                <?php if ((int)$r['id'] === (int)$me): ?><span class="badge b-key">you</span><?php endif; ?>
              </span>
            </div>
          </td>
          <td class="nowrap"><?= $active
              ? '<span class="badge b-ok">' . View::icon('check', 11) . 'Active</span>'
              : '<span class="badge b-warn">Disabled</span>' ?>
            <?php if ($r['locked_until'] !== null && strtotime((string)$r['locked_until']) > time()): ?>
              <span class="badge b-danger" title="Temporary throttle from failed logins">locked</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int)$r['failed_attempts'] ?></td>
          <td class="nowrap mono"><?= $r['last_login_at'] !== null ? e(substr((string)$r['last_login_at'], 0, 16)) : '<span class="hint">never</span>' ?></td>
          <td class="nowrap mono"><?= e(substr((string)$r['created_at'], 0, 16)) ?></td>
          <td class="ops">
            <?php if ((int)$r['id'] !== (int)$me): ?>
            <form method="post" action="/admin/admins/toggle"
                  data-confirm="<?= $active ? 'Disable' : 'Re-enable' ?> admin «<?= e((string)$r['username']) ?>»?<?= $active ? ' Their open sessions are signed out.' : '' ?>">
              <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm <?= $active ? 'danger' : '' ?>" type="submit"><?= $active ? 'Disable' : 'Enable' ?></button>
            </form>
            <?php else: ?>
            <span class="hint small">signed in</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
