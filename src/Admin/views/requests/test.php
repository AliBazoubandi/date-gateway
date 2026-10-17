<?php /** @var array $request @var array $result @var array $preview */
use Core\Str;
$r = $result;
?>
<h1>Test run — <?= e((string)$request['code']) ?></h1>
<section class="card">
  <table class="meta">
    <tr><td>SQL</td><td><code><?= e(Str::truncate((string)$request['sql_text'], 400)) ?></code></td></tr>
    <tr><td>Outcome</td><td><?php
      if ($r['ok'] === true) {
          echo Admin\View::badge('ok', 'OK');
      } elseif ($r['timed_out'] === true) {
          echo Admin\View::badge('timeout', 'TIMEOUT');
      } else {
          echo Admin\View::badge('error', 'FAILED');
      }
    ?></td></tr>
    <tr><td>Rows</td><td><?php
        echo (int)$r['row_count'];
        if ($r['truncated']) {
            echo ' — requested row limit (' . (int)$request['max_rows'] . ') reached; result is truncated';
        }
    ?></td></tr>
    <tr><td>Duration</td><td><?= (int)$r['duration_ms'] ?> ms · <?= e($limits) ?></td></tr>
    <?php if ($r['error'] !== ''): ?>
    <tr><td>Error</td><td class="msg"><code><?= e((string)$r['error']) ?></code></td></tr>
    <?php endif; ?>
  </table>
</section>
<?php if ($preview !== []): ?>
<section class="card">
  <h2>First <?= count($preview) ?> row<?= count($preview) === 1 ? '' : 's' ?></h2>
  <table class="rows">
    <tr><?php foreach (array_keys($preview[0]) as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr>
    <?php foreach ($preview as $row): ?>
      <tr><?php foreach ($row as $v): ?><td class="msg"><code><?= e((string)$v) ?></code></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">Full result never loaded — the fetch stops after the limit.</p>
</section>
<?php endif; ?>
<div class="actions">
  <a class="btn" href="/admin/requests/<?= (int)$request['id'] ?>">Edit request</a>
  <a class="btn" href="/admin/requests">Back to list</a>
</div>
