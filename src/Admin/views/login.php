<?php /** @var string $error @var string $username */
$err = $error ?? '';
?>
<div class="login-bg" aria-hidden="true">
  <i></i><i></i><i></i><i></i><i></i><i></i>
</div>
<section class="card login-card">
  <div style="text-align:center">
    <svg class="login-mark" width="48" height="48" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
      <path d="M16 3.2 26.4 9.2v14L16 29 5.6 23.2v-14Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
      <circle cx="16" cy="16" r="3" fill="currentColor"/>
      <circle cx="16" cy="6.9" r="1.4" fill="currentColor"/>
      <circle cx="23.4" cy="20.5" r="1.4" fill="currentColor"/>
      <circle cx="8.6" cy="20.5" r="1.4" fill="currentColor"/>
    </svg>
    <h1 style="font-size:19px;letter-spacing:-0.01em">Admin login</h1>
    <p class="hint" style="margin-top:2px">Data Gateway · Control Room</p>
  </div>
  <?php if ($err !== ''): ?><div class="flash error" style="margin-top:14px"><?= e($err) ?></div><?php endif; ?>
  <form method="post" action="/admin/login" class="form">
    <?= $csrf ?? '<input type="hidden" name="_csrf" value="' . e(Core\Csrf::token()) . '">' ?>
    <label>Username
      <input name="username" value="<?= e($username ?? '') ?>" required autofocus autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <div class="form-ops"><button class="btn btn-primary" type="submit">Sign in</button></div>
  </form>
  <p class="hint login-note">Every sign-in attempt — success or failure — is audited.</p>
</section>
