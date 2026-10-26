<?php if ($admin !== null): ?>
</main>
<footer class="foot wrap">
  <div class="foot-copy">
    <span>✨ All rights of this website are reserved for the Rahbarian Rabin Business Intelligence Team. ✨</span>
  </div>
  <div class="foot-meta">
    <span>Asia/Tehran · <?= e(now_str()) ?></span>
    <span class="foot-dot">·</span>
    <span>Control Room</span>
  </div>
</footer>
</div><!-- /.content -->
</div><!-- /.shell -->
<?php else: ?>
</main>
<footer class="foot login-foot">
  <span>✨ All rights of this website are reserved for the Rahbarian Rabin Business Intelligence Team. ✨</span>
</footer>
<?php endif; ?>

<!-- ================== page-level scaffolding (JS presentation only) ======== -->

<div class="palette" id="palette" role="dialog" aria-modal="true" aria-label="Quick search" hidden>
  <div class="palette-box">
    <input id="palette-q" type="search" placeholder="Search requests, clients, pages…" autocomplete="off" spellcheck="false" aria-label="Quick search">
    <ul class="palette-list" id="palette-list" aria-live="polite"></ul>
    <div class="palette-foot">
      <span><kbd class="kbd">↑↓</kbd> navigate</span>
      <span><kbd class="kbd">↵</kbd> open</span>
      <span><kbd class="kbd">Esc</kbd> close</span>
      <span id="palette-mode" class="g-flash" hidden></span>
    </div>
  </div>
</div>

<div class="d-scrim" id="drawer-scrim"></div>
<aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="Call inspector" aria-hidden="true">
  <div class="d-head">
    <div class="d-title">
      <b id="drawer-code">—</b>
      <small id="drawer-sub"></small>
    </div>
    <button type="button" class="icon-btn" id="drawer-close" title="Close (Esc)" aria-label="Close inspector">
      <?= Admin\View::icon('x', 16) ?>
    </button>
  </div>
  <div class="d-body" id="drawer-body"><div class="skel skel-line skel-line-lg"></div></div>
</aside>

<div class="modal" id="confirm" hidden role="dialog" aria-modal="true" aria-labelledby="confirm-title">
  <div class="modal-box danger">
    <div class="modal-ic"><?= Admin\View::icon('alert', 20) ?></div>
    <h3 id="confirm-title">Are you sure?</h3>
    <p id="confirm-text">This action cannot be undone.</p>
    <div class="m-actions">
      <button type="button" class="btn" id="confirm-cancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="confirm-ok">Confirm</button>
    </div>
  </div>
</div>

<div class="modal" id="cheatsheet" hidden role="dialog" aria-modal="true" aria-label="Keyboard shortcuts">
  <div class="modal-box">
    <h3>Keyboard shortcuts</h3>
    <p class="hint">Press <kbd class="kbd">?</kbd> anywhere to open this list.</p>
    <div class="cheat-grid" style="margin-top:12px">
      <div class="cheat-row"><span>Search</span><span class="keys"><kbd class="kbd">Ctrl</kbd><kbd class="kbd">K</kbd></span></div>
      <div class="cheat-row"><span>Overview</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">d</kbd></span></div>
      <div class="cheat-row"><span>Requests</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">r</kbd></span></div>
      <div class="cheat-row"><span>Connections</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">c</kbd></span></div>
      <div class="cheat-row"><span>Clients</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">u</kbd></span></div>
      <div class="cheat-row"><span>Logs</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">l</kbd></span></div>
      <div class="cheat-row"><span>Audit</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">a</kbd></span></div>
      <div class="cheat-row"><span>Admin users</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">m</kbd></span></div>
      <div class="cheat-row"><span>Home / overview</span><span class="keys"><kbd class="kbd">g</kbd><kbd class="kbd">h</kbd></span></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-primary" id="cheatsheet-ok">Got it</button></div>
  </div>
</div>

<script src="<?= \Admin\View::asset('/assets/js/admin.js') ?>" defer></script>
</body>
</html>
