<?php /** @var array $state the OverviewData::all() payload (render + poll share its shape). */
use Admin\View;
use Core\Str;

$s = $state;
$k = $s['kpis'];

/** Countdown/elapsed ring: 28px, r=12, circumference ≈75.4. */
$ring = static function (string $kind, ?int $remains, ?int $span): string {
    if ($kind === '' || $remains === null) {
        return '';
    }
    $c = 75.4;
    if ($kind === 'elapsed') {
        $frac = $span && $span > 0 ? min(1, $remains / $span) : 0;
    } else {
        $frac = 1; // a fresh countdown sits at full; JS depletes it each second
    }
    if ($remains < 60) {
        $tempo = 'danger-ring';
    } elseif ($remains < 300) {
        $tempo = 'warn-ring';
    } else {
        $tempo = $kind === 'elapsed' ? 'primary-ring' : 'ok-ring';
    }
    $txt = $kind === 'elapsed'
        ? ((int)$remains) . ''
        : ($remains < 60 ? $remains . 's' : ((int)floor($remains / 60) . 'm'));
    return '<div class="ring ' . $tempo . '" data-kind="' . e($kind) . '" data-remains="' . (int)$remains
        . '" data-span="' . (int)($span ?? 0) . '" aria-hidden="true">'
        . '<svg width="28" height="28" viewBox="0 0 28 28">'
        . '<circle class="bg" cx="14" cy="14" r="12" fill="none" stroke-width="2"/>'
        . '<circle class="fg" cx="14" cy="14" r="12" fill="none" stroke-width="2" stroke-linecap="round"'
        . ' stroke-dasharray="' . $c . '" stroke-dashoffset="' . round($c * (1 - $frac), 1) . '"/></svg>'
        . '<b>' . e($txt) . '</b></div>';
};

/** One pipeline card (PHP first paint; admin.js mirrors this exact shape). */
$card = static function (array $c) use ($ring): string {
    $cls = $c['status'] === 'running' ? ' running' : '';
    // handwritten badges: View::badge() escapes its text, which would mangle
    // an inline icon — mode badges carry their clock/zap glyph.
    $badges = $c['mode'] === 'instant'
        ? '<span class="badge b-mode instant">Instant</span>'
        : '<span class="badge b-mode delayed">' . View::icon('clock', 11) . 'Delayed</span>';
    $badges .= ' ' . View::badge('b-' . $c['status'], ucfirst($c['status']));
    $rowsInfo = $c['rows'] !== null ? ' · ' . number_format($c['rows']) . ' rows' : '';
    $dur = $c['dur'] !== null ? ' · ' . ($c['dur'] < 1 ? '<1s' : $c['dur'] . 's') : '';
    return '<article class="ex-card' . $cls . '" data-exec="' . (int)$c['id'] . '">'
        . '<div class="ex-top"><span class="ex-code">' . e($c['code']) . '</span><span class="ex-badges">' . $badges . '</span></div>'
        . '<span class="ex-name">' . e($c['name']) . '</span>'
        . ($c['err'] !== null ? '<span class="ex-err" title="' . e(Str::truncate((string)$c['err'], 200)) . '">' . e(Str::truncate((string)$c['err'], 110)) . '</span>' : '')
        . '<div class="ex-foot">'
        . '<span class="ex-conn">' . View::icon('database', 12) . e($c['conn']) . $rowsInfo . $dur . '</span>'
        . $ring((string)($c['ring'] ?? ''), $c['remains'], $c['span'])
        . '</div></article>';
};

$laneBody = static function (array $cards): string {
    if ($cards === []) {
        return '<div class="empty" style="padding:20px 10px;min-height:76px"><span style="max-width:none">Nothing in this lane right now.</span></div>';
    }
    return implode('', array_map(static fn(array $c): string => $c['html'], $cards));
};

/** Wrap built cards with a helper so lanes stay simple. */
$cardsOf = static function (array $list, object $card): array {
    return array_map(static fn(array $c): array => $c + ['html' => $card($c)], $list);
};

/** KPI sparkline (calls/hour, 24 buckets). */
$spark = static function (array $pts): string {
    $w = 100;
    $h = 30;
    $max = max(1, max($pts));
    $step = $w / max(1, count($pts) - 1);
    $xy = [];
    foreach ($pts as $i => $n) {
        $xy[] = round($i * $step, 1) . ',' . round($h - 3 - ($h - 8) * ($n / $max), 1);
    }
    $line = implode(' ', $xy);
    $last = count($xy) - 1;
    [$lx, $ly] = explode(',', $xy[$last]);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon class="area" points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '"/>'
        . '<polyline points="' . $line . '"/>'
        . '<circle class="dot" cx="' . $lx . '" cy="' . $ly . '" r="1.6"/></svg>';
};

/** 7x24 heat table (PHP mirror of the JS renderer). */
$heat = static function (array $hm, array $fails): string {
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $peak = max(1, (int)$hm['peak']);
    $lv = static fn(int $n): int => $n <= 0 ? 0 : ($n <= $peak * 0.25 ? 1 : ($n <= $peak * 0.5 ? 2 : ($n <= $peak * 0.75 ? 3 : 4)));
    $out = '<table class="heat"><tbody>';
    foreach ($days as $d => $name) {
        $out .= '<tr><td class="hw">' . $name . '</td>';
        for ($h = 0; $h < 24; $h++) {
            $n = (int)$hm['cells'][(int)$d][(int)$h];
            $f = $fails !== null ? (int)$fails[(int)$d][(int)$h] : 0;
            $now = ($d === (int)$hm['day'] && $h === (int)$hm['hour']) ? ' now' : '';
            $out .= '<td class="h' . $lv($n) . $now . '" data-cell="' . $d . 'h' . $h . '" title="'
                . $name . ' ' . sprintf('%02d', $h) . ':00 — '
                . ($n === 0 ? 'no activity' : ('#' . number_format($n) . ' started' . ($f > 0 ? ' · ' . $f . ' failed' : '')))
                . '"></td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table>'
        . '<div class="heat-legend"><span>less</span><i class="h0"></i><i class="h1"></i><i class="h2"></i><i class="h3"></i><i class="h4"></i><span>more</span><span>· peak '
        . number_format($peak) . '/hour</span></div>';
    return $out;
};

/** Connection load card (PHP mirror of the JS renderer). */
$connView = static function (array $c): string {
    $ok = $c['pct'] >= 100 ? 'danger-ring' : ($c['pct'] >= 75 ? 'warn-ring' : 'primary-ring');
    $r = 19;
    $circ = round(2 * M_PI * $r, 1);
    $off = round($circ * (1 - $c['pct'] / 100), 1);
    return '<article class="conn-card' . ($c['active'] ? '' : ' off') . '" data-conn="' . (int)$c['id'] . '">'
        . '<div class="conn-top"><span class="conn-ico">' . View::icon('database', 17) . '</span>'
        . '<div style="min-width:0"><div class="conn-name">' . e($c['name']) . '</div>'
        . '<div class="conn-host">' . e($c['db']) . ' · ' . e($c['db_name']) . '</div></div>'
        . ($c['active'] ? '' : ' <span class="badge b-warn">off</span>') . '</div>'
        . '<div class="conn-load">'
        . '<div><div class="cl-l">Load</div><b>' . (int)$c['running'] . ' <em>/ ' . (int)$c['cap'] . '</em></b></div>'
        . '<div class="load-ring ' . $ok . '" data-pct="' . (int)$c['pct'] . '" aria-hidden="true">'
        . '<svg viewBox="0 0 44 44"><circle class="bg" cx="22" cy="22" r="' . $r . '" fill="none" stroke-width="3.5"/>'
        . '<circle class="fg" cx="22" cy="22" r="' . $r . '" fill="none" stroke-width="3.5" stroke-linecap="round"'
        . ' stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $off . '"/></svg>'
        . '<b>' . (int)$c['pct'] . '%</b></div></div>'
        . '<span class="hint small">' . ($c['requests'] ?? 0) . ' active request' . (($c['requests'] ?? 0) === 1 ? '' : 's') . ' · ' . e($c['host']) . '</span>'
        . '</article>';
};

$sched = $cardsOf($s['lanes']['scheduled'], $card);
$runCards = $cardsOf($s['lanes']['running'], $card);
$rdy = $cardsOf($s['lanes']['ready'], $card);
$exp = $cardsOf($s['lanes']['expired'], $card);
?>
<div class="page-head">
  <div>
    <h1>Overview</h1>
    <div class="page-sub">Pipeline, activity and load across the gateway — refreshed live.</div>
  </div>
  <div class="page-tools">
    <span class="badge b-info b-live"><span id="poll-note">live · every 4s</span></span>
  </div>
</div>

<section class="kpis" id="kpis" aria-label="Key numbers">
  <div class="kpi" id="kpi-calls">
    <span class="kpi-l">Calls today</span>
    <b class="kpi-n" data-n="<?= (int)$k['calls_today'] ?>"><?= number_format((int)$k['calls_today']) ?></b>
    <?= $spark($k['spark']) ?>
  </div>
  <div class="kpi <?= ($k['ok_24h'] + $k['bad_24h']) === 0 ? '' : '' ?>" id="kpi-rate">
    <span class="kpi-l">Success rate · 24h</span>
    <b class="kpi-n"><?= $k['rate_24h'] === null ? '—' : (int)$k['rate_24h'] . '<small>%</small>' ?></b>
    <span class="kpi-s"><b><?= number_format($k['ok_24h']) ?></b> ok · <b><?= number_format($k['bad_24h']) ?></b> failed</span>
  </div>
  <div class="kpi <?= ($k['running'] ?? 0) > 0 ? 'live' : '' ?>" id="kpi-pipe">
    <span class="kpi-l">In the pipeline</span>
    <b class="kpi-n"><?= (int)$k['scheduled'] + (int)$k['running'] + (int)$k['ready'] ?></b>
    <span class="kpi-s">
      <b><?= (int)$k['scheduled'] ?></b> queued ·
      <b><?= (int)$k['running'] ?></b> running ·
      <b><?= (int)$k['ready'] ?></b> ready
    </span>
  </div>
  <div class="kpi <?= ($k['bad_24h'] ?? 0) > 0 ? 'alarm' : '' ?>" id="kpi-fails">
    <span class="kpi-l">Failures · 24h</span>
    <b class="kpi-n" data-n="<?= (int)$k['bad_24h'] ?>"><?= number_format((int)$k['bad_24h']) ?></b>
    <span class="kpi-s"><b><?= number_format((int)$k['expired']) ?></b> expired results cleaned up</span>
  </div>
  <div class="kpi" id="kpi-modes">
    <span class="kpi-l">Mode mix · 24h</span>
    <?php $tot = max(1, $k['inst_24h'] + $k['del_24h']); $ip = (int)round($k['inst_24h'] * 100 / $tot); ?>
    <b class="kpi-n"><?= $ip ?><small>% instant</small></b>
    <div class="split">
      <i style="flex:<?= $ip ?>;background:var(--warn)"></i>
      <i style="flex:<?= max(0, 100 - $ip) ?>;background:var(--info)"></i>
    </div>
    <span class="kpi-s"><b><?= number_format($k['inst_24h']) ?></b> instant · <b><?= number_format($k['del_24h']) ?></b> delayed</span>
  </div>
</section>

<section class="card" id="pipeline">
  <div class="card-head">
    <div class="card-title"><?= View::icon('activity', 16) ?><h2>Pipeline</h2></div>
    <div class="card-tools"><a class="btn btn-sm" href="/admin/logs"><?= View::icon('terminal', 13) ?><span>Logs</span></a></div>
  </div>
  <div class="lanes" id="lanes">
    <div class="lane lane-scheduled">
      <div class="lane-head"><span class="lane-name"><?= View::icon('clock', 14) ?>Scheduled</span><span class="lane-count" data-lane="scheduled"><?= count($s['lanes']['scheduled']) ?></span></div>
      <div class="lane-body"><?= $laneBody($sched) ?></div>
    </div>
    <div class="lane lane-running">
      <div class="lane-head"><span class="lane-name"><?= View::icon('pulse', 14) ?>Running</span><span class="lane-count" data-lane="running"><?= count($s['lanes']['running']) ?></span></div>
      <div class="lane-body"><?= $laneBody($runCards) ?></div>
    </div>
    <div class="lane lane-ready">
      <div class="lane-head"><span class="lane-name"><?= View::icon('download', 14) ?>Ready</span><span class="lane-count" data-lane="ready"><?= count($s['lanes']['ready']) ?></span></div>
      <div class="lane-body"><?= $laneBody($rdy) ?></div>
    </div>
    <div class="lane lane-expired">
      <div class="lane-head"><span class="lane-name"><?= View::icon('alert', 14) ?>Expired</span><span class="lane-count" data-lane="expired"><?= count($s['lanes']['expired']) ?></span></div>
      <div class="lane-body"><?= $laneBody($exp) ?></div>
    </div>
  </div>
</section>

<section class="grid-duo">
  <div class="card">
    <div class="card-head">
      <div class="card-title"><?= View::icon('grid', 16) ?><h2>Activity · last 7 days</h2></div>
    </div>
    <div class="heat-wrap" id="heatmap"><?= $heat($s['heatmap'], $s['heatmap']['fails']) ?></div>
  </div>
  <div class="card">
    <div class="card-head">
      <div class="card-title"><?= View::icon('database', 16) ?><h2>Connection load</h2></div>
      <div class="card-tools"><a class="btn btn-sm" href="/admin/connections"><?= View::icon('chevron-end', 13) ?><span>Manage</span></a></div>
    </div>
    <div class="conn-grid" id="conns"><?= implode('', array_map($connView, $s['connections'])) ?></div>
  </div>
</section>

<section class="card">
  <div class="card-head">
    <div class="card-title"><?= View::icon('alert', 16) ?><h2>Recent failures</h2></div>
    <div class="card-tools"><a class="btn btn-sm" href="/admin/logs?event=failed"><?= View::icon('filter', 13) ?><span>Filter in logs</span></a></div>
  </div>
  <div class="fail-list" id="fails">
<?php if ($s['fails'] === []): ?>
    <div class="empty"><b>Zero failures</b><span>No failed executions recorded. The last five appear here when they happen.</span></div>
<?php else: foreach ($s['fails'] as $f): ?>
    <article class="fail-row">
      <span class="fail-ic"><?= View::icon('alert', 14) ?></span>
      <div style="min-width:0">
        <div class="fail-code"><?= e($f['code']) ?><?php if ($f['err'] !== null): ?> <span class="badge b-fail"><?= e($f['err']) ?></span><?php endif; ?></div>
        <div class="fail-msg" title="<?= e((string)($f['msg'] ?? '')) ?>"><?= e(Str::truncate((string)($f['msg'] ?? 'Failed.'), 120)) ?></div>
      </div>
      <span class="fail-when"><?= e((string)($f['ago'] ?? '')) ?></span>
    </article>
<?php endforeach; endif; ?>
  </div>
</section>
