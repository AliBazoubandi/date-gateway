/* ============================================================================
   Data Gateway — Control Room front-end (vanilla ES2017+, no build step).
   Modules: theme, rail pin, user menu, toasts, confirms, palette + shortcuts,
   tabs, sorting, key copy, count-up, countdown rings, overview polling, live
   log tail, inspector drawer, CodeMirror mount. All presentation-only.
   ========================================================================== */
(function () {
  'use strict';

  var REDUCE = false;
  try { REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
  }
  function icon(name, size) {
    return '<svg class="i" width="' + (size || 14) + '" height="' + (size || 14)
      + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
      + ' stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="/assets/icons.svg#i-' + name + '"></use></svg>';
  }

  /* ------------------------------------------------------------ theme ---- */
  var themeBtn = $('#theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var light = document.documentElement.getAttribute('data-theme') === 'light';
      var next = light ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', next);
      store('gw-theme', next);
    });
  }

  /* ------------------------------------------------------------ rail pin - */
  var pin = $('#rail-pin');
  var railEl = $('#rail');
  if (load('gw-rail') === '1') {
    document.documentElement.classList.add('rail-open');
    if (railEl) { railEl.classList.add('rail-open'); }
    if (pin) { pin.setAttribute('aria-pressed', 'true'); }
  }
  if (pin) {
    pin.addEventListener('click', function () {
      var open = document.documentElement.classList.toggle('rail-open');
      if (railEl) { railEl.classList.toggle('rail-open', open); }
      pin.setAttribute('aria-pressed', open ? 'true' : 'false');
      store('gw-rail', open ? '1' : '0');
    });
  }

  /* ---------------------------------------------------------- user menu -- */
  var um = $('#user-menu'), umb = $('#um-btn');
  function closeMenu() { if (um) { um.classList.remove('open'); if (umb) { umb.setAttribute('aria-expanded', 'false'); } } }
  if (umb && um) {
    umb.addEventListener('click', function () {
      var open = um.classList.toggle('open');
      umb.setAttribute('aria-expanded', open ? 'true' : 'false');
      var item = $('.um-item', um);
      if (open && item) { item.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (!um.contains(e.target)) { closeMenu(); }
    });
  }

  /* -------------------------------------------------------------- toasts - */
  $$('.toasts .toast').forEach(function (t) {
    var x = $('.toast-x', t);
    function bye() { t.classList.add('bye'); setTimeout(function () { t.remove(); }, 240); }
    if (x) { x.addEventListener('click', bye); }
    setTimeout(bye, t.classList.contains('err') ? 6400 : 4600);
  });
  function toast(kind, msg) {
    var host = $('#toasts');
    if (!host) { return; }
    var el = document.createElement('div');
    el.className = 'toast ' + kind;
    el.innerHTML = icon(kind === 'err' ? 'alert' : (kind === 'ok' ? 'check' : 'info'), 15)
      + '<span>' + esc(msg) + '</span>'
      + '<button type="button" class="toast-x" aria-label="Dismiss">' + icon('x', 13) + '</button>';
    host.appendChild(el);
    var y = $('.toast-x', el);
    function bye() { el.classList.add('bye'); setTimeout(function () { el.remove(); }, 240); }
    if (y) { y.addEventListener('click', bye); }
    setTimeout(bye, kind === 'err' ? 6400 : 4600);
  }

  /* ------------------------------------------------- styled confirm modal - */
  var confirmBox = $('#confirm');
  var pendingForm = null;
  function askConfirm(form, message) {
    pendingForm = form;
    $('#confirm-text').textContent = message || 'This action cannot be undone.';
    confirmBox.hidden = false;
    $('#confirm-cancel').focus();
  }
  if (confirmBox) {
    $('#confirm-ok').addEventListener('click', function () {
      confirmBox.hidden = true;
      if (pendingForm) { pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit(); }
      pendingForm = null;
    });
    $('#confirm-cancel').addEventListener('click', function () { confirmBox.hidden = true; pendingForm = null; });
    confirmBox.addEventListener('mousedown', function (e) {
      if (e.target === confirmBox) { confirmBox.hidden = true; pendingForm = null; }
    });
  }
  $$('form[data-confirm]').forEach(function (f) {
    if (f.getAttribute('onsubmit')) { f.removeAttribute('onsubmit'); }
    f.addEventListener('submit', function (e) {
      if (pendingForm === f) { return; }            // re-entry from the modal's requestSubmit
      e.preventDefault();
      if (!confirmBox) {                            // scaffold missing → native fallback
        if (window.confirm(f.getAttribute('data-confirm'))) { f.submit(); }
        return;
      }
      askConfirm(f, f.getAttribute('data-confirm'));
    });
  });

  /* ------------------------------------------------- tabs (logs/clients) - */
  function tabs(container) {
    var tabBtns = $$('.tab', container);
    var root = container.parentElement;
    var panes = $$('[role="tabpanel"]', root);
    function focusTab(idx) {
      tabBtns.forEach(function (b, i) {
        b.classList.toggle('on', i === idx);
        b.setAttribute('aria-selected', i === idx ? 'true' : 'false');
      });
      panes.forEach(function (p, i) { p.hidden = i !== idx; });
      store('gw-tab:' + container.id, String(idx));
      if (idx === 0 && typeof Tail !== 'undefined') { Tail.kick(); }
    }
    tabBtns.forEach(function (b, i) {
      b.addEventListener('click', function () { focusTab(i); });
    });
    var saved = parseInt(load('gw-tab:' + container.id), 10);
    if (!isNaN(saved) && saved > 0 && saved < tabBtns.length) { focusTab(saved); }
  }
  $$('.tabs[id]').forEach(tabs);

  /* ------------------------------------------------ permission checklist -- */
  var permFilter = $('#perm-filter');
  var permAll = $('#perm-all');
  var permRows = $$('#perm-list .perm-row');
  var permCount = $('#perm-count');

  if (permFilter && permRows.length > 0) {
    permFilter.addEventListener('input', function () {
      var q = (permFilter.value || '').trim().toLowerCase();
      var visible = 0;
      permRows.forEach(function (row) {
        var term = row.getAttribute('data-search') || '';
        var match = !q || term.indexOf(q) !== -1;
        row.style.display = match ? 'flex' : 'none';
        if (match) { visible++; }
      });
      if (permCount) { permCount.textContent = String(visible); }
      if (permAll) {
        var visibleBoxes = permRows.filter(function (r) { return r.style.display !== 'none'; })
          .map(function (r) { return $('input[type="checkbox"]', r); })
          .filter(Boolean);
        permAll.checked = visibleBoxes.length > 0 && visibleBoxes.every(function (cb) { return cb.checked; });
      }
    });
  }

  if (permAll && permRows.length > 0) {
    permAll.addEventListener('change', function () {
      var check = permAll.checked;
      permRows.forEach(function (row) {
        if (row.style.display !== 'none') {
          var cb = $('input[type="checkbox"]', row);
          if (cb) { cb.checked = check; }
        }
      });
    });
  }

  /* ------------------------------------------- requests view toggle -------- */
  var viewToggle = $('#view-toggle');
  if (viewToggle) {
    var cardsEl = $('#req-cards');
    var tableEl = $('#req-table');
    var viewBtns = $$('button[data-view]', viewToggle);

    function setReqView(view) {
      viewBtns.forEach(function (b) {
        b.classList.toggle('on', b.getAttribute('data-view') === view);
      });
      if (cardsEl) { cardsEl.hidden = (view !== 'cards'); }
      if (tableEl) { tableEl.hidden = (view !== 'table'); }
      store('gw-requests-view', view);
    }

    viewBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setReqView(btn.getAttribute('data-view'));
      });
    });

    var savedView = load('gw-requests-view');
    if (savedView === 'cards' || savedView === 'table') {
      setReqView(savedView);
    }
  }

  /* ------------------------------------------------------ table sorting -- */
  $$('table.sortable').forEach(function (table) {
    var ths = $$('thead th', table);
    ths.forEach(function (th, idx) {
      th.setAttribute('tabindex', '0');
      th.setAttribute('role', 'button');
      th.setAttribute('aria-sort', 'none');
      th.classList.add('sortable');
      function apply(asc) {
        var rows = $$('tbody tr', table);
        var numeric = rows.length > 0;
        var cache = rows.map(function (r) {
          var cell = r.children[idx];
          var s = cell ? cell.textContent.trim() : '';
          var clean = s.replace(/,(\d{3})/g, '$1').replace(/\s?(min|s)$/, '');
          var n = /^[-+]?\d+(\.\d+)?$/.test(clean) ? parseFloat(clean) : NaN;
          if (isNaN(n)) { numeric = false; }
          return [n, s.toLowerCase(), r];
        });
        cache.sort(function (a, b) {
          var v = numeric ? (a[0] - b[0]) : (a[1] < b[1] ? -1 : a[1] > b[1] ? 1 : 0);
          return asc ? v : -v;
        });
        var tb = $('tbody', table);
        var frag = document.createDocumentFragment();
        cache.forEach(function (c) { frag.appendChild(c[2]); });
        if (tb) { tb.appendChild(frag); }
        ths.forEach(function (h, i) { h.setAttribute('aria-sort', i === idx ? (asc ? 'ascending' : 'descending') : 'none'); });
      }
      function toggle() { apply(th.getAttribute('aria-sort') !== 'ascending'); }
      th.addEventListener('click', toggle);
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
      });
    });
  });

  /* ------------------------------------------- copy button for keys ----- */
  $$('code.key').forEach(function (code) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'key-copy';
    btn.setAttribute('aria-label', 'Copy to clipboard');
    btn.title = 'Copy';
    btn.innerHTML = '<svg class="cp" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
      + '<svg class="ok" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    btn.addEventListener('click', function () {
      var text = code.textContent || '';
      var done = function () {
        btn.classList.add('done');
        setTimeout(function () { btn.classList.remove('done'); }, 1300);
      };
      function fallback() {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, fallback);
      } else { fallback(); }
    });
    code.parentNode.insertBefore(btn, code.nextSibling);
  });

  /* --------------------------------------------------------- count-up --- */
  if (!REDUCE && document.body.dataset.page === 'overview') {
    $$('.kpi-n[data-n]').forEach(function (el) {
      var end = parseInt(el.getAttribute('data-n'), 10);
      if (isNaN(end) || end < 1 || end > 9999) { return; }
      var t0 = null;
      function step(now) {
        if (t0 === null) { t0 = now; }
        var p = Math.min(1, (now - t0) / 620);
        el.textContent = String(Math.round(end * (1 - Math.pow(1 - p, 3))));
        if (p < 1) { requestAnimationFrame(step); }
      }
      requestAnimationFrame(step);
    });
  }

  /* ------------------------------------------- countdown rings (1s tick) - */
  var ticking = false;
  function tick() {
    $$('.ring[data-remains]').forEach(function (el) {
      var remains = parseInt(el.getAttribute('data-remains'), 10);
      var kind = el.getAttribute('data-kind');
      var span = parseInt(el.getAttribute('data-span'), 10) || remains || 1;
      var b = $('b', el);
      var fg = $('.fg', el);
      if (kind === 'elapsed') {
        remains += 1;
        var p2 = Math.min(1, remains / span);
        if (fg) { fg.setAttribute('stroke-dashoffset', String(Math.max(0, (Math.round(75.4 * (1 - p2) * 10) / 10)))); }
        if (b) { b.textContent = remains >= 3600 ? Math.floor(remains / 3600) + 'h' : (remains >= 60 ? Math.floor(remains / 60) + 'm' : remains + 's'); }
        if (p2 >= 1) { el.removeAttribute('data-remains'); if (b) { b.textContent = 'max'; } }
        else { el.setAttribute('data-remains', String(remains)); }
      } else if (remains > 0) {
        remains -= 1;
        var frac2 = span > 0 ? remains / span : 0;
        if (fg) { fg.setAttribute('stroke-dashoffset', String(Math.round(75.4 * (1 - frac2) * 10) / 10)); }
        if (b) {
          b.textContent = remains < 60 ? remains + 's' : (remains < 3600 ? Math.floor(remains / 60) + 'm' : Math.floor(remains / 3600) + 'h');
        }
        if (remains < 300 && !el.classList.contains('warn-ring') && !el.classList.contains('danger-ring')) {
          el.classList.remove('ok-ring'); el.classList.add('warn-ring');
        }
        if (remains < 60 && !el.classList.contains('danger-ring')) {
          el.classList.remove('warn-ring'); el.classList.add('danger-ring');
        }
        el.setAttribute('data-remains', String(remains));
      } else if (b) {
        b.textContent = 'now';
      }
    });
  }
  if (document.body.dataset.page === 'overview' && $$('.ring[data-remains]').length > 0) {
    setInterval(function () { if (!document.hidden) { tick(); } }, 1000);
  }

  /* ================================================= POLLING + RENDER === */
  if (document.body.dataset.page === 'overview') {
    var stateUrl = '/admin/api/state';
    var failNote = 0;

    function sparkSvgJs(pts, danger) {
      var w = 100, h = 30;
      var max = Math.max.apply(null, pts.concat([1]));
      var step = w / Math.max(1, pts.length - 1);
      var xy = pts.map(function (n, i) {
        return (i * step).toFixed(1) + ',' + (h - 3 - (h - 8) * (n / max)).toFixed(1);
      });
      var last = xy[xy.length - 1].split(',');
      return '<svg class="spark' + (danger ? ' danger-line' : '') + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">'
        + '<polygon class="area" points="0,' + h + ' ' + xy.join(' ') + ' ' + w + ',' + h + '"/>'
        + '<polyline points="' + xy.join(' ') + '"/>'
        + '<circle class="dot" cx="' + last[0] + '" cy="' + last[1] + '" r="1.6"/></svg>';
    }

    function execCard(c) {
      var badges = c.mode === 'instant'
        ? '<span class="badge b-mode instant">Instant</span>'
        : '<span class="badge b-mode delayed">' + icon('clock', 11) + 'Delayed</span>';
      badges += ' <span class="badge b-' + esc(c.status) + '">' + esc(c.status.charAt(0).toUpperCase() + c.status.slice(1)) + '</span>';
      var ring = '';
      if (c.ring) {
        var cls = c.ring === 'elapsed' ? 'primary-ring' : (c.remains < 60 ? 'danger-ring' : (c.remains < 300 ? 'warn-ring' : (c.ring === 'elapsed' ? 'primary-ring' : 'ok-ring')));
        var spanAttr = c.ring === 'elapsed' ? c.span : Math.max(1, c.remains || 1);
        ring = '<div class="ring ' + cls + '" data-kind="' + esc(c.ring) + '" data-remains="' + (c.remains || 0) + '" data-span="' + spanAttr + '">'
          + '<svg width="28" height="28" viewBox="0 0 28 28">'
          + '<circle class="bg" cx="14" cy="14" r="12" fill="none" stroke-width="2"/>'
          + '<circle class="fg" cx="14" cy="14" r="12" fill="none" stroke-width="2" stroke-linecap="round" stroke-dasharray="75.4" stroke-dashoffset="0"/></svg>'
          + '<b>' + (c.ring === 'elapsed' ? (c.remains || 0) : (c.remains < 60 ? c.remains + 's' : Math.floor(c.remains / 60) + 'm')) + '</b></div>';
      }
      return '<article class="ex-card' + (c.status === 'running' ? ' running' : '') + '" data-exec="' + emId(c.id) + '">'
        + '<div class="ex-top"><span class="ex-code">' + esc(c.code) + '</span><span class="ex-badges">' + badges + '</span></div>'
        + '<span class="ex-name">' + esc(c.name) + '</span>'
        + (c.err ? '<span class="ex-err" title="' + esc(String(c.err).slice(0, 200)) + '">' + esc(String(c.err).slice(0, 110)) + '</span>' : '')
        + '<div class="ex-foot"><span class="ex-conn">' + icon('database', 12) + esc(c.conn)
        + (c.rows !== null && c.rows !== undefined ? ' · ' + c.rows.toLocaleString() + ' rows' : '')
        + (c.dur !== null && c.dur !== undefined ? ' · ' + (c.dur < 1 ? '<1s' : c.dur + 's') : '') + '</span>'
        + ring + '</div></article>';
    }
    function emId(n) { return String(n); }

    function emptyLane() {
      return '<div class="empty" style="padding:20px 10px;min-height:76px"><span style="max-width:none">Nothing in this lane right now.</span></div>';
    }

    function heatHtml(hm) {
      var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      var peak = Math.max(1, hm.peak);
      function lv(n) { return n <= 0 ? 0 : n <= peak * 0.25 ? 1 : n <= peak * 0.5 ? 2 : n <= peak * 0.75 ? 3 : 4; }
      var out = '<table class="heat"><tbody>';
      days.forEach(function (name, d) {
        out += '<tr><td class="hw">' + name + '</td>';
        for (var h = 0; h < 24; h++) {
          var n = hm.cells[d] ? (hm.cells[d][h] || 0) : 0;
          var f = hm.fails[d] ? (hm.fails[d][h] || 0) : 0;
          var now = (d === hm.day && h === hm.hour) ? ' now' : '';
          out += '<td class="h' + lv(n) + now + '" data-cell="' + d + 'h' + h + '" title="' + name + ' ' + ('00' + h).slice(-2)
            + ':00 — ' + (n === 0 ? 'no activity' : ('#' + n + ' started' + (f > 0 ? ' · ' + f + ' failed' : ''))) + '"></td>';
        }
        out += '</tr>';
      });
      out += '</tbody></table><div class="heat-legend"><span>less</span><i class="h0"></i><i class="h1"></i><i class="h2"></i><i class="h3"></i><i class="h4"></i><span>more</span><span>· peak '
        + peak + '/hour</span></div>';
      return out;
    }

    function connHtml(c) {
      var cls = c.pct >= 100 ? 'danger-ring' : c.pct >= 75 ? 'warn-ring' : 'primary-ring';
      var circ = 119.4, off = (circ * (1 - c.pct / 100)).toFixed(1);
      return '<article class="conn-card' + (c.active ? '' : ' off') + '" data-conn="' + c.id + '">'
        + '<div class="conn-top"><span class="conn-ico">' + icon('database', 17) + '</span>'
        + '<div style="min-width:0"><div class="conn-name">' + esc(c.name) + '</div>'
        + '<div class="conn-host">' + esc(c.db) + ' · ' + esc(c.db_name) + '</div></div>'
        + (c.active ? '' : ' <span class="badge b-warn">off</span>') + '</div>'
        + '<div class="conn-load"><div><div class="cl-l">Load</div><b>' + c.running + ' <em>/ ' + c.cap + '</em></b></div>'
        + '<div class="load-ring ' + cls + '" data-pct="' + c.pct + '"><svg viewBox="0 0 44 44">'
        + '<circle class="bg" cx="22" cy="22" r="19" fill="none" stroke-width="3.5"/>'
        + '<circle class="fg" cx="22" cy="22" r="19" fill="none" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="' + circ + '" stroke-dashoffset="' + off + '"/></svg>'
        + '<b>' + c.pct + '%</b></div></div>'
        + '<span class="hint small">' + c.requests + ' active request' + (c.requests === 1 ? '' : 's') + ' · ' + esc(c.host) + '</span></article>';
    }

    function failHtml(f) {
      return '<article class="fail-row"><span class="fail-ic">' + icon('alert', 14) + '</span>'
        + '<div style="min-width:0"><div class="fail-code">' + esc(f.code)
        + (f.err ? ' <span class="badge b-fail">' + esc(f.err) + '</span>' : '') + '</div>'
        + '<div class="fail-msg" title="' + esc(f.msg || '') + '">' + esc(String(f.msg || 'Failed.').slice(0, 120)) + '</div></div>'
        + '<span class="fail-when">' + esc(f.ago || '') + '</span></article>';
    }

    function beatHtml(state, age, label) {
      var b = $('#beat');
      if (!b || age === null || age === undefined) { return; }
      b.className = 'beat ' + state;
      b.setAttribute('data-age', String(age));
      var lbl = $('.beat-label', b);
      if (lbl) { lbl.textContent = 'worker · ' + label; }
    }

    function applyState(st) {
      var k = st.kpis;
      var setN = function (sel, html) { var el = $(sel); if (el) { el.innerHTML = html; } };
      setN('#kpi-calls .kpi-n', k.calls_today.toLocaleString());

      // rate tile
      var rateHtml = (k.rate_24h === null ? '—' : k.rate_24h + '<small>%</small>');
      setN('#kpi-rate .kpi-n', rateHtml);
      setN('#kpi-rate .kpi-s', '<b>' + k.ok_24h.toLocaleString() + '</b> ok · <b>' + k.bad_24h.toLocaleString() + '</b> failed');
      var rateEl = $('#kpi-rate');
      if (rateEl) { rateEl.className = 'kpi' + ((k.ok_24h + k.bad_24h) === 0 ? '' : ''); }

      // pipeline tile
      setN('#kpi-pipe .kpi-n', String(k.scheduled + k.running + k.ready));
      setN('#kpi-pipe .kpi-s', '<b>' + k.scheduled + '</b> queued · <b>' + k.running + '</b> running · <b>' + k.ready + '</b> ready');
      var pipeEl = $('#kpi-pipe');
      if (pipeEl) { pipeEl.className = 'kpi' + (k.running > 0 ? ' live' : ''); }

      // failures tile
      setN('#kpi-fails .kpi-n', k.bad_24h.toLocaleString());
      setN('#kpi-fails .kpi-s', '<b>' + k.expired.toLocaleString() + '</b> expired results cleaned up');
      var fEl = $('#kpi-fails');
      if (fEl) { fEl.className = 'kpi' + (k.bad_24h > 0 ? ' alarm' : ''); }

      // mode mix tile
      var tot = Math.max(1, k.inst_24h + k.del_24h);
      var ip = Math.round(k.inst_24h * 100 / tot);
      setN('#kpi-modes .kpi-n', ip + '<small>% instant</small>');
      setN('#kpi-modes', $('#kpi-modes').innerHTML.replace(/<div class="split">[\s\S]*?<\/div>/,
        '<div class="split"><i style="flex:' + ip + ';background:var(--warn)"></i><i style="flex:' + (100 - ip) + ';background:var(--info)"></i></div>'));
      setN('#kpi-modes .kpi-s', '<b>' + k.inst_24h.toLocaleString() + '</b> instant · <b>' + k.del_24h.toLocaleString() + '</b> delayed');

      // spark under calls tile
      var calls = $('#kpi-calls');
      if (calls) {
        var old = $('.spark', calls);
        if (old) { old.remove(); }
        calls.insertAdjacentHTML('beforeend', sparkSvgJs(k.spark, false));
      }

      // lanes
      var L = st.lanes;
      var renderLane = function (key, cards) {
        var body = $('.lane-' + key + ' .lane-body', $('#lanes'));
        var count = $('.lane-' + key + ' .lane-count', $('#lanes'));
        if (count) { count.textContent = String(cards.length); }
        if (body) { body.innerHTML = cards.length === 0 ? emptyLane() : Array.prototype.map.call(cards, execCard).join(''); }
      };
      renderLane('scheduled', L.scheduled);
      renderLane('running', L.running);
      renderLane('ready', L.ready);
      renderLane('expired', L.expired);

      // heatmap + connections + fails
      var hm = $('#heatmap');
      if (hm) { hm.innerHTML = heatHtml(st.heatmap); }
      var cn = $('#conns');
      if (cn) { cn.innerHTML = st.connections.map(connHtml).join('') || ''; }
      var fl = $('#fails');
      if (fl) { fl.innerHTML = st.fails.length === 0 ? '<div class="empty"><b>Zero failures</b><span>No failed executions recorded. The last five appear here when they happen.</span></div>' : st.fails.map(failHtml).join(''); }

      // heartbeat chip
      beatHtml(st.heartbeat.state, st.heartbeat.age, st.heartbeat.label);
      var note = $('#poll-note');
      if (note) { note.textContent = 'live · ' + (st.now ? st.now.slice(11, 19) : '') + ' Tehran'; }
    }

    function pollState() {
      if (document.hidden) { return; }
      fetch(stateUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
        .then(function (j) { failNote = 0; applyState(j); })
        .catch(function () {
          failNote += 1;
          var note = $('#poll-note');
          if (note && failNote >= 3) { note.textContent = 'live · reconnecting…'; }
        });
    }
    setInterval(pollState, 4000);
    pollState();
  }

  /* ===================================================== LIVE LOG TAIL = */
  var Tail = (function () {
    var body = $('#term-body');
    if (!body) { return { kick: function () {} }; }
    var pauseBtn = $('#tail-pause');
    var dot = $('#term-dot');
    var selClient = $('#tail-client');
    var selEvent = $('#tail-event');
    var paused = false;
    var lastId = 0;
    var firstFill = true;

    function url() {
      var u = '/admin/api/tail?since=' + lastId;
      if (selClient && selClient.value) { u += '&client_id=' + encodeURIComponent(selClient.value); }
      if (selEvent && selEvent.value) { u += '&event=' + encodeURIComponent(selEvent.value); }
      return u;
    }

    function rowHtml(r, isNew) {
      return '<div class="t-row' + (isNew ? ' t-new' : '') + '" data-call="' + esc(r.call || '') + '">'
        + '<span class="t-time">' + esc(r.ts) + '</span>'
        + '<span class="t-ev ev-' + esc(r.ev) + '">' + esc(r.ev) + '</span>'
        + '<span class="t-code">' + esc(r.code || '') + '</span>'
        + '<span class="t-client">' + esc(r.client || '') + '</span>'
        + '<span class="t-msg">' + esc(r.msg || '') + (r.dur !== null && r.dur !== undefined ? ' · ' + r.dur + 'ms' : '')
        + (r.rows !== null && r.rows !== undefined ? ' · ' + r.rows.toLocaleString() + ' rows' : '') + '</span></div>';
    }

    function fetchRows() {
      if (paused || document.hidden) { return; }
      fetch(url(), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw 0; } return r.json(); })
        .then(function (j) {
          var rows = j.rows || [];
          if (rows.length > 0) {
            var frag = '';
            var i = 0;
            for (; i < rows.length; i++) {
              frag += rowHtml(rows[i], !firstFill);
              lastId = Math.max(lastId, rows[i].id);
            }
            var scrollBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 30;
            var skels = $$('.skel', body);
            if (skels.length) { skels.forEach(function (s) { s.remove(); }); }
            if ($('.term-empty', body)) { $('.term-empty', body).remove(); }
            body.insertAdjacentHTML('beforeend', frag);
            var rows2 = $$('.t-row', body);
            if (rows2.length > 220) {
              body.innerHTML = $$('.t-row', body).slice(-160).map(function (n) { return n.outerHTML; }).join('');
            }
            if (scrollBottom || firstFill) { body.scrollTop = body.scrollHeight; }
            firstFill = false;
          } else if (firstFill) {
            body.innerHTML = '<div class="term-empty">Waiting for the first log rows…</div>';
            firstFill = false;
          }
        })
        .catch(function () { /* keep retrying silently */ });
    }

    if (pauseBtn) {
      pauseBtn.addEventListener('click', function () {
        paused = !paused;
        pauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
        if (dot) { dot.classList.toggle('paused', paused); }
        pauseBtn.innerHTML = (paused ? icon('play', 13) : icon('pause', 13)) + '<span>' + (paused ? 'Resume' : 'Pause') + '</span>';
      });
    }
    [selClient, selEvent].forEach(function (s) {
      if (s) { s.addEventListener('change', function () { lastId = 0; firstFill = true; fetchRows(); }); }
    });
    body.addEventListener('keydown', function (e) {
      if (e.key === ' ' && pauseBtn) { e.preventDefault(); pauseBtn.click(); }
    });
    setInterval(fetchRows, 3000);
    fetchRows();
    return { kick: fetchRows };
  })();

  /* ============================================== CALL INSPECTOR DRAWER = */
  var Drawer = (function () {
    var drawer = $('#drawer');
    if (!drawer) { return { close: function () {} }; }
    var scrim = $('#drawer-scrim');
    var bodyBox = $('#drawer-body');
    var lastFocus = null;

    function close() {
      document.body.classList.remove('d-open');
      drawer.setAttribute('aria-hidden', 'true');
      if (lastFocus) { try { lastFocus.focus(); } catch (e) {} lastFocus = null; }
    }
    $('#drawer-close').addEventListener('click', close);
    if (scrim) { scrim.addEventListener('click', close); }

    function row(a, b, cls) {
      return '<div class="d-row"><span>' + esc(a) + '</span><b class="' + (cls || '') + '">' + (b === null || b === undefined || b === '' ? '-' : b) + '</b></div>';
    }
    function dRowList(rows) { return '<div class="d-rows">' + rows.map(function (r) { return row(r[0], r[1], r[2]); }).join('') + '</div>'; }

    var EV_DONE = ['succeeded', 'downloaded', 'ready', 'instant_executed'];
    var EV_BAD = ['failed', 'denied', 'webhook_failed', 'timeout'];
    var EV_WARN = ['rate_limited', 'expired', 'shared'];

    function tlItem(ev, ts, msg) {
      var cls = EV_DONE.indexOf(ev) >= 0 ? 'done' : EV_BAD.indexOf(ev) >= 0 ? 'err' : EV_WARN.indexOf(ev) >= 0 ? 'warn'
        : (ev === 'scheduled' || ev === 'started' || ev === 'submitted') ? 'info' : '';
      return '<div class="tl-item ' + cls + '"><span class="tl-dot"></span>'
        + '<div class="tl-top"><span class="tl-t">' + esc(ev) + '</span><span class="tl-when">' + esc(ts) + '</span></div>'
        + (msg ? '<div class="tl-d">' + esc(msg) + '</div>' : '') + '</div>';
    }

    function dt(s) { return s ? esc(String(s).slice(0, 19)) : '-'; }
    function fmtSize(n) {
      if (n === null || n === undefined) { return '-'; }
      if (n > 1048576) { return (n / 1048576).toFixed(1) + ' MB'; }
      if (n > 1024) { return (n / 1024).toFixed(1) + ' KB'; }
      return n + ' B';
    }

    function open(callId) {
      lastFocus = document.activeElement;
      $('#drawer-code').textContent = 'call ' + callId.slice(0, 10) + '…';
      $('#drawer-sub').textContent = 'loading…';
      bodyBox.innerHTML = '<div class="skel skel-row" style="height:220px"></div>';
      document.body.classList.add('d-open');
      drawer.setAttribute('aria-hidden', 'false');
      $('#drawer-close').focus();
      fetch('/admin/api/call?id=' + encodeURIComponent(callId), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw 0; } return r.json(); })
        .then(function (j) {
          var c = j.call;
          $('#drawer-code').textContent = 'r/' + c.request.code;
          $('#drawer-sub').textContent = c.client.name + ' · ' + c.ts + ' Tehran';
          var html = '';
          var exDur = j.exec && j.exec.dur !== null ? j.exec.dur + 's' : null;
          html += '<div class="d-kpis">'
            + '<div class="d-kpi"><small>State</small><b>' + esc(j.exec ? j.exec.status : 'pending') + '</b></div>'
            + '<div class="d-kpi"><small>Rows</small><b>' + (j.exec && j.exec.rows !== null ? j.exec.rows.toLocaleString() : '-') + '</b></div>'
            + '<div class="d-kpi"><small>Duration</small><b>' + (exDur === null ? '-' : exDur) + '</b></div>'
            + '</div>';
          html += '<div class="d-sec">Call</div>';
          html += dRowList([
            ['Call id', '<span class="mono">' + c.id + '</span>', 'mono'],
            ['Client', esc(c.client.name) + ' (' + esc(c.client.type) + ')'],
            ['Request', esc(c.request.name) + ' · ' + esc(c.request.code)],
            ['Mode', esc(c.request.mode)],
            ['Submitted', dt(c.ts)],
            ['Source IP', esc(c.ip) || '-'],
            ['Shared', c.shared ? 'yes' : 'no']
          ]);
          if (j.exec) {
            var e = j.exec;
            html += '<div class="d-sec">Execution #' + e.id + '</div>';
            html += dRowList([
              ['Scheduled', dt(e.sched)],
              ['Started', dt(e.start)],
              ['Finished', dt(e.fin)],
              ['Ready', dt(e.ready)],
              ['Expires', dt(e.exp)],
              ['Result size', fmtSize(e.size)],
              ['Truncated', e.trunc ? 'yes' : 'no'],
              (e.err ? ['Error' + (e.err_code ? ' (' + e.err_code + ')' : ''), esc(String(e.err).slice(0, 240)), 'err'] : null)
            ].filter(Boolean));
          }
          html += '<div class="d-sec">Timeline</div>';
          html += '<div class="tl">' + (j.logs.length === 0 ? '<div class="tl-item"><span class="tl-dot"></span><div class="tl-d">No route events recorded.</div></div>'
            : j.logs.map(function (l) { return tlItem(l.ev, esc(String(l.ts).slice(11, 19)), l.msg || (l.dur !== null ? l.dur + 'ms' : '')); }).join('')) + '</div>';
          html += '<div class="d-sec">Webhook</div>';
          if (j.webhook) {
            var w = j.webhook;
            html += dRowList([
              ['State', esc(w.state)],
              ['Attempt', String(w.attempt)],
              ['HTTP status', w.status === null ? '-' : String(w.status)],
              ['Sent', dt(w.sent)],
              ['Next retry', dt(w.retry)],
              (w.error ? ['Last error', esc(w.error), 'err'] : null)
            ].filter(Boolean));
          } else {
            html += '<p class="hint">No webhook configured for this call.</p>';
          }
          bodyBox.innerHTML = html;
        })
        .catch(function () {
          bodyBox.innerHTML = '<div class="flash error">' + icon('alert', 15) + 'Could not load the call.</div>';
          $('#drawer-sub').textContent = 'unavailable';
        });
    }

    // delegation: any row carrying a call id opens the inspector
    document.addEventListener('click', function (e) {
      var row = e.target.closest ? e.target.closest('[data-call]') : null;
      if (row && row.getAttribute('data-call')) { open(row.getAttribute('data-call')); }
    });

    return { close: close, open: open };
  })();

  /* -------------------------------------------------- command palette --- */
  var Palette = (function () {
    var pal = $('#palette');
    if (!pal) { return null; }
    var q = $('#palette-q');
    var list = $('#palette-list');
    var openBtn = $('#palette-open');
    var stripBtn = $('#strip-search');
    var mode = $('#palette-mode');
    var PAGES = [
      { t: 'Overview', h: '/admin', icon: 'grid' },
      { t: 'Connections', h: '/admin/connections', icon: 'database' },
      { t: 'New connection', h: '/admin/connections/new', icon: 'plus' },
      { t: 'Requests', h: '/admin/requests', icon: 'file' },
      { t: 'New request', h: '/admin/requests/new', icon: 'plus' },
      { t: 'Clients', h: '/admin/clients', icon: 'users' },
      { t: 'New client', h: '/admin/clients/new', icon: 'plus' },
      { t: 'Logs', h: '/admin/logs', icon: 'terminal' },
      { t: 'Audit log', h: '/admin/audit', icon: 'shield' },
      { t: 'Admin users', h: '/admin/admins', icon: 'user' }
    ].map(function (p) { return { t: p.t, h: p.h, g: 'Pages', ico: p.icon }; });
    var ENTITIES = null;          // fetched once on first open
    var shown = [];
    var act = 0;

    function fetchEntities() {
      if (ENTITIES !== null) { return; }
      fetch('/admin/api/quick', { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw 0; } return r.json(); })
        .then(function (j) { ENTITIES = j.items || []; render(q.value); })
        .catch(function () { ENTITIES = []; });
      ENTITIES = [];               // render pages while loading
    }

    function setAct(idx) {
      if (shown.length === 0) { return; }
      var old = $('.p-item.act', list);
      if (old) { old.classList.remove('act'); }
      act = Math.max(0, Math.min(idx, shown.length - 1));
      // data-idx indexes `shown`; nth-child would misalign across group labels
      var nw = $('.p-item[data-idx="' + act + '"]', list);
      if (nw) { nw.classList.add('act'); nw.scrollIntoView({ block: 'nearest' }); }
    }

    function render(filterText) {
      var s = (filterText || '').trim().toLowerCase();
      shown = [];
      var all = PAGES.concat(ENTITIES || []);
      for (var i = 0; i < all.length; i++) {
        if (s === '' || all[i].t.toLowerCase().indexOf(s) !== -1) { shown.push(all[i]); }
      }
      if (s !== '') {
        // relevance: prefix matches first
        shown.sort(function (a, b) {
          var pa = a.t.toLowerCase().indexOf(s) === 0 ? 0 : 1;
          var pb = b.t.toLowerCase().indexOf(s) === 0 ? 0 : 1;
          return pa - pb;
        });
      }
      var html = '';
      var lastG = null;
      for (var j = 0; j < shown.length; j++) {
        var it = shown[j];
        if (it.g !== lastG) {
          html += '<li class="p-group">' + esc(it.g) + '</li>';
          lastG = it.g;
        }
        html += '<li class="p-item" data-idx="' + j + '" role="option">'
          + icon(it.ico || 'file', 16) + '<span>' + esc(it.t) + '</span>'
          + '<span class="p-hint kbd">↵</span></li>';
      }
      if (shown.length === 0) {
        list.innerHTML = '<li class="palette-none">No match. Try a request code (e.g. <b>daily_prices</b>).</li>';
      } else {
        list.innerHTML = html;
        setAct(0);
      }
      $$('.p-item', list).forEach(function (li) {
        li.addEventListener('click', function () { go(parseInt(li.getAttribute('data-idx'), 10)); });
        li.addEventListener('mouseenter', function () { setAct(parseInt(li.getAttribute('data-idx'), 10)); });
      });
    }

    function go(idx) {
      if (idx < 0 || idx >= shown.length) { return; }
      close();
      location.href = shown[idx].h;
    }

    function openPal() {
      pal.hidden = false;
      fetchEntities();
      render('');
      q.value = '';
      q.focus();
      if (mode) { mode.hidden = false; mode.textContent = ''; }
    }
    function close() {
      pal.hidden = true;
      if (openBtn) { openBtn.focus(); }
    }

    if (openBtn) { openBtn.addEventListener('click', openPal); }
    if (stripBtn) { stripBtn.addEventListener('click', openPal); }
    q.addEventListener('input', function () { render(q.value); });
    q.addEventListener('keydown', function (e) {
      if (shown.length === 0 && e.key !== 'Escape') { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setAct(act + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setAct(act - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); go(act); }
      else if (e.key === 'Escape') { close(); }
    });
    pal.addEventListener('mousedown', function (e) { if (e.target === pal) { close(); } });

    return {
      open: openPal,
      close: close,
      isOpen: function () { return !pal.hidden; }
    };
  })();

  /* ----------------------------------- global shortcuts (g-keys, ?, CtrlK) */
  var cheat = $('#cheatsheet');
  var gPending = false;
  var gTimer = null;
  var ROUTES = {
    d: '/admin',
    h: '/admin',
    r: '/admin/requests',
    c: '/admin/connections',
    u: '/admin/clients',
    l: '/admin/logs',
    a: '/admin/audit',
    m: '/admin/admins'
  };
  document.addEventListener('keydown', function (e) {
    var el = e.target;
    var typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT'
      || (el.isContentEditable === true));

    if (e.key === 'Escape') {
      if (cheat && !cheat.hidden) { cheat.hidden = true; return; }
      if (confirmBox && !confirmBox.hidden) { confirmBox.hidden = true; pendingForm = null; return; }
      if (document.body.classList.contains('d-open')) { Drawer.close(); return; }
      return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (Palette) { Palette.isOpen() ? Palette.close() : Palette.open(); }
      return;
    }
    if (typing || !Palette || Palette.isOpen()) { return; }

    if (gPending) {
      var route = ROUTES[e.key.toLowerCase()];
      gPending = false;
      clearTimeout(gTimer);
      if (route) { location.href = route; return; }
    }
    if (e.key === 'g' || e.key === 'G') {
      gPending = true;
      clearTimeout(gTimer);
      gTimer = setTimeout(function () { gPending = false; }, 900);
      return;
    }
    if (e.key === '/' && !typing) {
      e.preventDefault();
      if (Palette) { Palette.open(); }
      return;
    }
    if (e.key === '?') {
      if (cheat) { cheat.hidden = false; $('#cheatsheet-ok').focus(); }
      return;
    }
  });
  if (cheat) {
    $('#cheatsheet-ok').addEventListener('click', function () { cheat.hidden = true; });
    cheat.addEventListener('mousedown', function (e2) { if (e2.target === cheat) { cheat.hidden = true; } });
  }

  /* -------------------------------------------- CodeMirror (editor page) - */
  $$('textarea[data-cm]').forEach(function (ta) {
    if (typeof CodeMirror === 'undefined') { return; }   // graceful plain-textarea fallback
    var cm = CodeMirror.fromTextArea(ta, {
      mode: 'text/x-sql',
      theme: 'gateway',
      lineNumbers: true,
      matchBrackets: true,
      styleActiveLine: true,
      indentUnit: 2,
      lineWrapping: true,
      extraKeys: {
        'Ctrl-Space': function () {},
        'Shift-Tab': function (cm) { cm.indentSelection('subtract'); }
      }
    });
    ta.removeAttribute('required');
    cm.on('change', function () { cm.save(); });
    if (ta.form) {
      ta.form.addEventListener('submit', function () { cm.save(); });
    }
  });
})();
