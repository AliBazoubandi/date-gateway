<?php /** @var array $items @var array $f @var array $entities @var array $actions
  @var array $admins @var int $total @var int $page @var int $pages @var string $qs */

// Diff values can be long (sql_text); display is capped, full snapshots stay in admin_audit_log.
$cell = static function (string $v): string {
    if ($v === '') {
        return '<span class="hint">—</span>';
    }
    $shown = \Core\Str::truncate($v, 400);
    $extra = strlen($v) > 400 ? ' <span class="hint">(+' . (strlen($v) - 400) . ' chars in DB)</span>' : '';
    return '<code>' . e($shown) . '</code>' . $extra;
};
?>
<h1>Audit log</h1>
<section class="card">
  <form method="get" action="/admin/audit" class="form filters">
    <label>Entity
      <select name="entity_type">
        <option value="">— all —</option>
        <?php foreach ($entities as $en): ?>
        <option value="<?= e($en) ?>" <?= $f['entity_type'] === $en ? 'selected' : '' ?>><?= e($en) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Action
      <select name="action">
        <option value="">— all —</option>
        <?php foreach ($actions as $ac): ?>
        <option value="<?= e($ac) ?>" <?= $f['action'] === $ac ? 'selected' : '' ?>><?= e($ac) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Admin
      <select name="admin_user_id">
        <option value="">— all —</option>
        <?php foreach ($admins as $ad): ?>
        <option value="<?= (int)$ad['id'] ?>" <?= (int)$f['admin_user_id'] === (int)$ad['id'] ? 'selected' : '' ?>>
          <?= e((string)$ad['username']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>From <input type="date" name="date_from" value="<?= e($f['date_from']) ?>"></label>
    <label>To <input type="date" name="date_to" value="<?= e($f['date_to']) ?>"></label>
    <div class="form-ops">
      <button type="submit">Filter</button>
      <a class="btn" href="/admin/audit">Reset</a>
    </div>
  </form>
</section>

<section class="card">
  <p class="hint"><?= number_format($total) ?> audit entr<?= $total === 1 ? 'y' : 'ies' ?>
    · page <?= (int)$page ?>/<?= (int)$pages ?> · newest first · snapshots hold full records, only changed fields are shown</p>
<?php if ($items === []): ?>
  <p class="hint">No audit entries match these filters.</p>
<?php endif; ?>
<?php foreach ($items as $item): $row = $item['row']; $diff = $item['diff']; ?>
  <details class="audit-row">
    <summary>
      <?= e(substr((string)$row['timestamp'], 0, 16)) ?>
      · <?= Admin\View::badge((string)$row['entity_type'], (string)$row['entity_type']) ?>
      <?php if ((string)$row['action'] === 'delete' || str_ends_with((string)$row['action'], '_failed')): ?>
        <?= Admin\View::badge('error', (string)$row['action']) ?>
      <?php elseif ((string)$row['action'] === 'test_run'): ?>
        <?= Admin\View::badge('timeout', 'test_run') ?>
      <?php else: ?>
        <?= Admin\View::badge('ok', (string)$row['action']) ?>
      <?php endif; ?>
      <strong>#<?= (int)$row['entity_id'] ?> <?= e((string)($row['entity_name'] ?? '')) ?></strong>
      <span class="hint">by <?= $row['admin_name'] !== null ? e((string)$row['admin_name']) : 'unknown' ?>
        · <?= count($diff) ?> field<?= count($diff) === 1 ? '' : 's' ?> changed</span>
    </summary>
<?php if ($diff === []): ?>
    <p class="hint">No field differences recorded (value snapshots identical).</p>
<?php else: ?>
    <table class="rows diff">
      <tr><th>Field</th><th>Before</th><th>After</th></tr>
      <?php foreach ($diff as $d): ?>
      <tr>
        <td><strong><?= e($d['field']) ?></strong></td>
        <td class="msg"><?= $cell($d['before']) ?></td>
        <td class="msg"><?= $cell($d['after']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
<?php endif; ?>
  </details>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
  <div class="form-ops pager">
    <?php if ($page > 1): ?>
      <a class="btn sm" href="/admin/audit?<?= e($qs) ?>page=<?= $page - 1 ?>">&larr; Newer</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
      <a class="btn sm" href="/admin/audit?<?= e($qs) ?>page=<?= $page + 1 ?>">Older &rarr;</a>
    <?php endif; ?>
    <span class="hint">page <?= (int)$page ?> of <?= (int)$pages ?></span>
  </div>
<?php endif; ?>
</section>
