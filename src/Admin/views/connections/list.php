<?php /** @var array $rows */
?>
<h1>Connections</h1>
<div class="actions"><a class="btn" href="/admin/connections/new"><?= Admin\View::icon('plus', 14) ?> New connection</a></div>
<section class="card">
<table class="rows sortable">
  <thead>
  <tr><th>Name</th><th>Type</th><th>Host / DB</th><th>Concur.</th><th>Requests</th><th>State</th><th></th></tr>
  </thead>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><strong><?= e((string)$r['name']) ?></strong></td>
    <td><?= Admin\View::badge('div-' . (string)$r['db_type'], (string)$r['db_type']) ?></td>
    <td><?= e((string)$r['host']) ?><?= (int)$r['port'] > 0 ? ':' . (int)$r['port'] : '' ?> / <?= e((string)$r['db_name']) ?></td>
    <td><?= $r['max_concurrency'] === null ? 'default' : (int)$r['max_concurrency'] ?></td>
    <td><?= (int)$r['requests_count'] ?></td>
    <td><?= $r['is_active'] ? Admin\View::badge('ok', 'active') : Admin\View::badge('off', 'inactive') ?></td>
    <td class="ops">
      <a class="btn sm" href="/admin/connections/<?= (int)$r['id'] ?>">Edit</a>
      <form method="post" action="/admin/connections/delete" class="inline" onsubmit="return confirm('Delete connection “<?= e((string)$r['name']) ?>” ?')">
        <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">Delete</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</section>
