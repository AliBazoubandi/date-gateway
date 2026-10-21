<?php /** @var array $rows */
?>
<h1>Requests</h1>
<div class="actions"><a class="btn" href="/admin/requests/new"><?= Admin\View::icon('plus', 14) ?> New request</a></div>
<section class="card">
<table class="rows sortable">
  <thead>
  <tr><th>Code</th><th>Name</th><th>Mode</th><th>Connection</th><th>Delay</th><th>TTL</th><th>Timeout</th><th>Max rows</th><th>State</th><th></th></tr>
  </thead>
<?php foreach ($rows as $r): ?>
  <?php if ($r['response_mode'] === 'instant'): ?>
    <?php $delay = '-'; $ttl = '-'; ?>
  <?php else: ?>
    <?php $delay = (int)$r['delay_minutes'] . ' min'; $ttl = (int)$r['ttl_minutes'] . ' min'; ?>
  <?php endif; ?>
  <tr>
    <td><strong><?= e((string)$r['code']) ?></strong></td>
    <td><?= e((string)$r['name']) ?></td>
    <td><?= $r['response_mode'] === 'instant' ? Admin\View::badge('instant', 'Instant') : Admin\View::badge('ok', 'Delayed') ?></td>
    <td><?= e((string)$r['connection_name']) ?> <?= Admin\View::badge('div-' . (string)$r['db_type'], (string)$r['db_type']) ?></td>
    <td><?= e($delay) ?></td>
    <td><?= e($ttl) ?></td>
    <td><?= (int)$r['timeout_seconds'] ?>s</td>
    <td><?= number_format((int)$r['max_rows']) ?></td>
    <td><?= $r['is_active'] ? Admin\View::badge('ok', 'active') : Admin\View::badge('off', 'inactive') ?></td>
    <td class="ops">
      <a class="btn sm" href="/admin/requests/<?= (int)$r['id'] ?>">Edit</a>
      <form method="post" action="/admin/requests/test" class="inline">
        <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm">Test run</button>
      </form>
      <form method="post" action="/admin/requests/delete" class="inline" onsubmit="return confirm('Delete request “<?= e((string)$r['code']) ?>” ?')">
        <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">Delete</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</section>
