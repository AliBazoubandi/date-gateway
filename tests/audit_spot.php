<?php
// Spot-check: audit snapshots must hold full records, so the viewer can show
// exactly what changed (esp. sql_text for requests).
require __DIR__ . '/../src/Core/bootstrap.php';

$row = db()->one(
    "SELECT entity_type, action, entity_name, before_json, after_json
     FROM admin_audit_log
     WHERE entity_type = ?
     ORDER BY id DESC
     LIMIT 1",
    ['request']
);
if ($row === null) {
    echo 'no request audit row found (run admin_smoke first)', PHP_EOL;
    exit(1);
}
echo $row['entity_type'], ' / ', $row['action'], ' / ', (string)$row['entity_name'], PHP_EOL;

$before = json_decode((string)$row['before_json'], true);
$after = json_decode((string)$row['after_json'], true);
if (!is_array($before) || !is_array($after)) {
    echo 'snapshot parse FAIL', PHP_EOL;
    exit(1);
}
$changed = [];
foreach ($before as $k => $v) {
    if (($after[$k] ?? null) !== $v) {
        $changed[] = $k . ': ' . json_encode($v) . ' -> ' . json_encode($after[$k]);
    }
}
echo $changed === [] ? 'no field changed (FAIL for an update row)' : 'changed fields:', PHP_EOL;
echo $changed === [] ? '' : implode(PHP_EOL, $changed), PHP_EOL;
