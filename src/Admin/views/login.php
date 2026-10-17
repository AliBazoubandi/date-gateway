<?php /** @var string $error @var string $username */
?>
<section class="card slim">
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
    <button type="submit">Log in</button>
  </form>
</section>
