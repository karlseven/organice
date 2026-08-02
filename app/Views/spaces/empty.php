<?php
/**
 * @var array<string,mixed> $space
 * @var bool $canEdit
 */
?>
<div class="home">
  <div class="empty-state">
    <?= icon('book', 32) ?>
    <h1><?= e((string)$space['title']) ?></h1>
    <p class="muted"><?= e(t('home.space_empty')) ?></p>
    <?php if ($canEdit): ?>
      <button class="btn" type="button" data-new-page data-space="<?= (int)$space['id'] ?>">
        <?= icon('plus', 14) ?> <?= e(t('page.new_first')) ?>
      </button>
    <?php endif; ?>
  </div>
</div>
