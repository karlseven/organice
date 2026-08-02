<?php
/**
 * @var int $status
 * @var string $message
 */
// titles come from the string catalogue, keyed by status
$key = in_array($status, [403,404,413,415,419,422,429,500], true) ? 'error.' . $status : 'error.generic';
?>
<div class="home">
  <div class="empty-state">
    <p class="error-code"><?= (int)$status ?></p>
    <h1><?= e(t($key)) ?></h1>
    <?php if ($message !== ''): ?>
      <p class="muted"><?= e($message) ?></p>
    <?php endif; ?>
    <a class="btn" href="<?= e(lurl('/')) ?>"><?= e(t('error.back')) ?></a>
  </div>
</div>
