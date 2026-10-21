<?php /** @var array $rows */
use Admin\View;
?>
<div class="page-head">
  <div>
    <h1>Connections</h1>
    <div class="page-sub"><?= count($rows) ?> source server<?= count($rows) === 1 ? '' : 's' ?> · credentials stay encrypted at rest.</div>
  </div>
  <div class="page-tools">
    <a class="btn btn-primary" href="/admin/connections/new"><?= View::icon('plus', 15) ?><span>New connection</span></a>
  </div>
</div>

<?php if ($rows === []): ?>
<section class="card">
  <div class="empty">
    <?= View::icon('database', 34) ?>
    <b>No connections yet</b>
    <span>A connection holds (encrypted) credentials for one source server. Requests run against exactly one connection.</span>
    <a class="btn btn-primary" href="/admin/connections/new"><?= View::icon('plus', 14) ?><span>New connection</span></a>
  </div>
</section>
<?php else: ?>
<div class="conn-grid" style="--min:280px">
<?php foreach ($rows as $r): ?>
  <article class="conn-card<?= (int)$r['is_active'] === 0 ? ' off' : '' ?>">
    <div class="conn-top">
      <span class="conn-ico"><?= View::icon('database', 17) ?></span>
      <div style="min-width:0">
        <div class="conn-name"><?= e((string)$r['name']) ?></div>
        <div class="conn-host"><?= e((string)$r['host']) ?><?= (int)$r['port'] > 0 ? ':' . (int)$r['port'] : '' ?></div>
      </div>
    </div>
    <div class="req-meta">
      <?php
      $type = (string)$r['db_type'];
      $tBadge = '<span class="badge b-key">' . e($type) . '</span>';
      echo $tBadge;
      echo (int)$r['is_active'] === 1
          ? '<span class="badge b-ok">' . View::icon('check', 11) . 'Active</span>'
          : '<span class="badge b-warn">Inactive</span>';
      if (!empty($r['allow_writes'])) {
          echo '<span class="badge b-warn" title="Scripts mode: multi-statement batches allowed">' . View::icon('zap', 11) . 'Scripts</span>';
      }
      ?>
    </div>
    <div class="hint small">
      db: <b class="mono"><?= e((string)$r['db_name']) ?></b> · user: <?= e((string)$r['username']) ?><br>
      <?= (int)$r['requests_count'] ?> request<?= (int)$r['requests_count'] === 1 ? '' : 's' ?> · concurrency: <?= $r['max_concurrency'] === null ? 'default' : (int)$r['max_concurrency'] ?>
    </div>
    <div class="ops" style="justify-content:space-between">
      <span class="hint small">password: stored encrypted</span>
      <span class="ops">
        <a class="btn btn-sm" href="/admin/connections/<?= (int)$r['id'] ?>">Edit</a>
        <?php if ((int)$r['requests_count'] === 0): ?>
        <form method="post" action="/admin/connections/delete" data-confirm="Delete connection “<?= e((string)$r['name']) ?>”? Its stored credentials are removed with it." class="inline">
          <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm danger" type="submit"><?= View::icon('x', 12) ?></button>
        </form>
        <?php endif; ?>
      </span>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
