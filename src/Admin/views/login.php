<?php /** @var string $error @var string $username */
?>
<section class="card slim login-card">
  <div class="login-mark" aria-hidden="true">
    <svg width="44" height="44" viewBox="0 0 32 32" focusable="false">
      <path d="M16 3.2 26.4 9.2v14L16 29 5.6 23.2v-14Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
      <circle cx="16" cy="16" r="3" fill="currentColor"/>
      <circle cx="16" cy="6.9" r="1.4" fill="currentColor"/>
      <circle cx="23.4" cy="20.5" r="1.4" fill="currentColor"/>
      <circle cx="8.6" cy="20.5" r="1.4" fill="currentColor"/>
    </svg>
  </div>
  <h1>Admin login</h1>
  <?php if ($error !== ''): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/admin/login" class="form">
    <?= $csrf ?? '<input type="hidden" name="_csrf" value="' . e(Core\Csrf::token()) . '">' ?>
    <label>Username
      <input name="username" value="<?= e($username ?? '') ?>" required autofocus autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <div class="form-ops"><button type="submit">Log in</button></div>
  </form>
  <p class="hint login-note">Sign in with your admin account. Every login is audited.</p>
</section>
