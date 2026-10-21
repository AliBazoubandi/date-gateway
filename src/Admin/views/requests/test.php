<?php /** @var array $request @var array $result @var array $preview */
use Admin\View;
use Core\Str;
$r = $result;
$ok = $r['ok'] === true;
?>
<div class="page-head">
  <div>
    <h1>Test run — <?= e((string)$request['code']) ?></h1>
    <div class="page-sub">Live sample against <?= e((string)($request['connection_name'] ?? 'its connection')) ?> · <?= e($limits) ?></div>
  </div>
  <div class="page-tools">
    <a class="btn" href="/admin/requests/<?= (int)$request['id'] ?>"><?= View::icon('arrow-start', 14) ?><span>Edit request</span></a>
    <a class="btn" href="/admin/requests">Back to list</a>
  </div>
</div>

<section class="card">
  <div class="inline" style="justify-content:space-between">
    <div class="chips">
      <?php if ($ok): ?>
        <span class="badge b-ok b-live"><?= View::icon('check', 11) ?>OK</span>
      <?php elseif ($r['timed_out'] === true): ?>
        <span class="badge b-timeout"><?= View::icon('clock', 11) ?>Timeout</span>
      <?php else: ?>
        <span class="badge b-fail"><?= View::icon('alert', 11) ?>Failed</span>
      <?php endif; ?>
      <span class="chip"><b><?= number_format((int)$r['row_count']) ?></b>&nbsp;rows</span>
      <span class="chip"><b><?= (int)$r['duration_ms'] ?></b>&nbsp;ms</span>
      <?php if ($r['truncated']): ?><span class="chip on">truncated at the request's row limit</span><?php endif; ?>
    </div>
  </div>
  <?php if ($r['error'] !== ''): ?>
  <div class="flash error" style="margin-top:12px">
    <?= Admin\View::icon('alert', 15) ?>
    <code style="background:none;border:0;padding:0;color:inherit"><?= e((string)$r['error']) ?></code>
  </div>
  <?php endif; ?>
</section>

<?php if ($preview !== []): ?>
<section class="card">
  <div class="card-head">
    <div class="card-title"><?= View::icon('download', 15) ?><h2>First <?= count($preview) ?> row<?= count($preview) === 1 ? '' : 's' ?></h2></div>
    <div class="card-tools"><span class="hint small">Full result never loaded — the fetch stops at the limit.</span></div>
  </div>
  <div class="table-scroll">
    <table class="rows">
      <thead><tr><?php foreach (array_keys($preview[0]) as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($preview as $row): ?>
        <tr><?php foreach ($row as $v): ?><td class="msg"><code><?= e((string)$v) ?></code></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php elseif ($ok): ?>
<section class="card">
  <div class="empty"><b>No rows</b><span>The SELECT ran fine but returned no rows for the current data.</span></div>
</section>
<?php endif; ?>
