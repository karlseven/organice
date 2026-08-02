<?php
/**
 * @var string|null $error
 * @var string $next
 */
use Core\Csrf;
?>
<div class="auth">
  <form class="card auth-card" method="post" action="<?= e(url('/login')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">

    <h1><?= e(t('auth.signin')) ?></h1>
    <p class="muted"><?= e(t('auth.admin_note')) ?></p>

    <?php if ($error): ?>
      <p class="form-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <label for="email"><?= e(t('auth.email')) ?></label>
    <input id="email" name="email" type="email" required autocomplete="username"
           value="<?= e($email ?? '') ?>" autofocus>

    <label for="password"><?= e(t('auth.password')) ?></label>
    <input id="password" name="password" type="password" required autocomplete="current-password">

    <button class="btn btn-block" type="submit"><?= e(t('auth.signin')) ?></button>
  </form>
</div>
