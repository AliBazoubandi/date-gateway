<script>
/* Presentation only: wires the light/dark toggle button in the topbar. */
(function () {
  var b = document.getElementById('theme-toggle');
  if (!b) { return; }
  b.addEventListener('click', function () {
    var dark = !document.documentElement.classList.contains('dark');
    document.documentElement.classList.toggle('dark', dark);
    try { localStorage.setItem('gw-theme', dark ? 'dark' : 'light'); } catch (e) {}
  });
})();
</script>
</main>
<footer class="wrap foot"><span>Asia/Tehran · <?= e(now_str()) ?></span></footer>
</body>
</html>
