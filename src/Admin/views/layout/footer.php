</main>
<footer class="wrap foot"><span>Asia/Tehran · <?= e(now_str()) ?></span></footer>
</div><!-- /.content -->
</div><!-- /.shell -->

<!-- Command palette: client-side navigation only; Esc / Ctrl+K / backdrop closes. -->
<div class="palette" id="palette" role="dialog" aria-modal="true" aria-label="Quick search" hidden>
  <div class="palette-box">
    <input id="palette-q" type="text" placeholder="Jump to pages… (Esc to close)" autocomplete="off" spellcheck="false">
    <ul class="palette-list" id="palette-list" aria-live="polite"></ul>
  </div>
</div>

<script>
/* Presentation-only behaviors: theme toggle, command palette,
   copy-on-click for revealed keys, sortable tables, stat count-up. */
(function () {
  'use strict';
  var reduce = false;
  try { reduce = matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  /* ---------- theme toggle ---------- */
  var tb = document.getElementById('theme-toggle');
  if (tb) {
    tb.addEventListener('click', function () {
      var dark = !document.documentElement.classList.contains('dark');
      document.documentElement.classList.toggle('dark', dark);
      try { localStorage.setItem('gw-theme', dark ? 'dark' : 'light'); } catch (e) {}
    });
  }

  /* ---------- command palette ---------- */
  var openBtn = document.getElementById('palette-open');
  var pal = document.getElementById('palette');
  if (openBtn && pal) {
    var q = document.getElementById('palette-q');
    var list = document.getElementById('palette-list');
    var PAGES = [
      { t: 'Dashboard', h: '/admin' },
      { t: 'Connections', h: '/admin/connections' },
      { t: 'New connection', h: '/admin/connections/new' },
      { t: 'Requests', h: '/admin/requests' },
      { t: 'New request', h: '/admin/requests/new' },
      { t: 'Clients', h: '/admin/clients' },
      { t: 'New client', h: '/admin/clients/new' },
      { t: 'Request logs', h: '/admin/logs' },
      { t: 'Audit log', h: '/admin/audit' }
    ];
    var shown = [], act = 0;

    var setAct = function (idx) {
      var old = list.children[act];
      if (old && shown.length > 0) { old.className = ''; }
      act = idx;
      var nw = list.children[idx];
      if (nw) { nw.className = 'act'; }
    };

    function render() {
      list.innerHTML = '';
      if (shown.length === 0) {
        var li = document.createElement('li');
        li.className = 'palette-none';
        li.textContent = 'No pages match.';
        list.appendChild(li);
        return;
      }
      for (var i = 0; i < shown.length; i++) {
        (function (idx) {
          var li = document.createElement('li');
          li.textContent = shown[idx].t;
          li.className = idx === act ? 'act' : '';
          var go = document.createElement('span');
          go.className = 'go';
          go.textContent = 'Enter ↵';
          li.appendChild(go);
          li.addEventListener('click', function () { go(idx); });
          li.addEventListener('mouseenter', function () {
            if (act !== idx) { setAct(idx); }
          });
          list.appendChild(li);
        })(i);
      }
    }
    function filter() {
      var s = q.value.trim().toLowerCase();
      shown = [];
      for (var i = 0; i < PAGES.length; i++) {
        if (s === '' || PAGES[i].t.toLowerCase().indexOf(s) !== -1) {
          shown.push(PAGES[i]);
        }
      }
      act = 0;
      render();
    }
    function goTo(idx) {
      if (idx < 0 || idx >= shown.length) { return; }
      pal.hidden = true;
      document.body.classList.remove('palette-open');
      location.href = shown[idx].h;
    }
    function open() {
      pal.hidden = false;
      document.body.classList.add('palette-open');
      q.value = '';
      filter();
      q.focus();
    }
    function close() {
      pal.hidden = true;
      document.body.classList.remove('palette-open');
      openBtn.focus();
    }
    openBtn.addEventListener('click', open);
    q.addEventListener('input', filter);
    q.addEventListener('keydown', function (e) {
      if (shown.length === 0 && e.key !== 'Escape') { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setAct(Math.min(act + 1, shown.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setAct(Math.max(act - 1, 0)); }
      else if (e.key === 'Enter') { e.preventDefault(); goTo(act); }
      else if (e.key === 'Escape') { close(); }
    });
    pal.addEventListener('mousedown', function (e) {
      if (e.target === pal) { close(); }
    });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        if (pal.hidden) { open(); } else { close(); }
      } else if (e.key === 'Escape' && !pal.hidden) {
        close();
      }
    });
  }

  /* ---------- copy button beside revealed keys ---------- */
  var keys = document.querySelectorAll('code.key');
  for (var k = 0; k < keys.length; k++) {
    (function (code) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'key-copy';
      btn.setAttribute('aria-label', 'Copy to clipboard');
      btn.title = 'Copy';
      btn.innerHTML =
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
        + '<svg class="ok" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
      btn.addEventListener('click', function () {
        var text = code.textContent || '';
        var done = function () {
          btn.classList.add('done');
          setTimeout(function () { btn.classList.remove('done'); }, 1300);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, function () { fallback(); });
        } else { fallback(); }
        function fallback() {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
      code.parentNode.insertBefore(btn, code.nextSibling);
    })(keys[k]);
  }

  /* ---------- client-side sorting for unpaginated list tables ---------- */
  var tables = document.querySelectorAll('table.sortable');
  for (var t = 0; t < tables.length; t++) {
    (function (table) {
      var ths = table.querySelectorAll('thead th');
      for (var i = 0; i < ths.length; i++) {
        (function (th, idx) {
          th.setAttribute('tabindex', '0');
          th.setAttribute('role', 'button');
          th.setAttribute('aria-sort', 'none');
          var apply = function (asc) {
            var all = table.querySelectorAll('tr');
            var rows = [];
            for (var r = 0; r < all.length; r++) {
              if (all[r].children[0] && all[r].children[0].tagName === 'TD') { rows.push(all[r]); }
            }
            var numeric = rows.length > 0;
            var cache = [];
            for (var r = 0; r < rows.length; r++) {
              var s = rows[r].children[idx] ? rows[r].children[idx].textContent.trim() : '';
              var clean = s.replace(/,(\d{3})/g, '$1').replace(/\s?(min|s)$/i, '');
              var n = /^[-+]?\d+(\.\d+)?$/.test(clean) ? parseFloat(clean) : NaN;
              if (isNaN(n)) { numeric = false; }
              cache.push([n, s.toLowerCase(), rows[r]]);
            }
            var cmp = function (a, b) {
              var v = numeric
                ? (a[0] - b[0])
                : (a[1] < b[1] ? -1 : a[1] > b[1] ? 1 : 0);
              return asc ? v : -v;
            };
            cache.sort(cmp);
            var frag = document.createDocumentFragment();
            for (var r = 0; r < cache.length; r++) { frag.appendChild(cache[r][2]); }
            table.appendChild(frag);
            for (var j = 0; j < ths.length; j++) {
              ths[j].setAttribute('aria-sort', j === idx ? (asc ? 'ascending' : 'descending') : 'none');
            }
          };
          var toggle = function () {
            var dir = th.getAttribute('aria-sort');
            apply(dir === 'ascending' ? false : true);
          };
          th.addEventListener('click', toggle);
          th.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
          });
        })(ths[i], i);
      }
    })(tables[t]);
  }

  /* ---------- count-up on dashboard stat tiles ---------- */
  if (!reduce) {
    var nums = document.querySelectorAll('.stats .stat-n');
    for (var s = 0; s < nums.length; s++) {
      (function (el) {
        var end = parseInt(el.textContent, 10);
        if (isNaN(end) || end < 1) { return; }
        var t0 = null, DUR = 620;
        var step = function (now) {
          if (t0 === null) { t0 = now; }
          var p = Math.min(1, (now - t0) / DUR);
          var eased = 1 - Math.pow(1 - p, 3);
          el.textContent = String(Math.round(end * eased));
          if (p < 1) { requestAnimationFrame(step); }
          else { el.textContent = String(end); }
        };
        requestAnimationFrame(step);
      })(nums[s]);
    }
  }
})();
</script>
</body>
</html>
