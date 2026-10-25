<?php /** @var array $rows */
use Core\Str;
?>
<h1>Clients</h1>
<div class="actions"><a class="btn" href="/admin/clients/new"><?= Admin\View::icon('plus', 14) ?> New client</a></div>
<section class="card">
<table class="rows sortable">
  <thead>
  <tr><th>Name</th><th>Type</th><th>Permissions</th><th>Webhook</th><th>Created</th><th>State</th><th></th></tr>
  </thead>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><strong><?= e((string)$r['name']) ?></strong></td>
    <td><?= e((string)$r['type']) ?></td>
    <td><?= (int)$r['permissions_count'] ?> request(s)</td>
    <td><?= $r['webhook_url'] ? e(Str::truncate((string)$r['webhook_url'], 40)) : '—' ?></td>
    <td><?= e(substr((string)$r['created_at'], 0, 16)) ?></td>
    <td><?= $r['is_active'] ? Admin\View::badge('ok', 'active') : Admin\View::badge('off', 'inactive') ?></td>
    <td class="ops">
      <a class="btn sm" href="/admin/clients/<?= (int)$r['id'] ?>">Edit / permissions</a>
      <form method="post" action="/admin/clients/delete" class="inline" onsubmit="return confirm('Delete client “<?= e((string)$r['name']) ?>”?')">
        <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger"><?= Admin\View::icon('trash', 12) ?> Delete</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</section>
