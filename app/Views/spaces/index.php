<?php
/** @var array<int,array<string,mixed>> $spaces */
use Core\Auth;
use Core\Settings;
?>
<div class="home">
  <header class="home-head">
    <h1><?= e(Settings::get('site_title', SITE_NAME)) ?></h1>
    <p class="lead"><?= e(Settings::get('site_tagline', 'Documentation')) ?></p>
  </header>

  <?php if ($spaces === []): ?>
    <div class="empty-state">
      <?= icon('book', 32) ?>
      <p><?= e(t('home.empty')) ?></p>
      <?php if (Auth::isAdmin()): ?>
        <a class="btn" href="<?= e(url('/admin')) ?>"><?= e(t('home.empty_admin')) ?></a>
      <?php elseif (!Auth::check()): ?>
        <p class="muted"><?= e(t('home.empty_signin')) ?> <a href="<?= e(url('/login')) ?>"><?= e(t('nav.signin')) ?></a></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="space-grid">
      <?php foreach ($spaces as $s): ?>
        <?php css_add('.accent-' . (int)$s['id'] . ' { --accent: ' . preg_replace('/[^#0-9a-f]/i', '', (string)$s['accent']) . '; }'); ?>
        <a class="space-card accent-<?= (int)$s['id'] ?>" href="<?= e(Core\Site::spaceUrl((string)$s['slug'])) ?>">
          <span class="space-card-icon"><?= icon('book', 20) ?></span>
          <h2><?= e((string)$s['title']) ?></h2>
          <?php if ($s['description'] !== ''): ?>
            <p><?= e((string)$s['description']) ?></p>
          <?php endif; ?>
          <p class="space-card-meta">
            <?= e((int)$s['page_count'] === 1 ? t('home.pages_one') : t('home.pages_many', ['count' => (int)$s['page_count']])) ?>
            <?php if ($s['visibility'] !== 'public'): ?>
              · <span class="pill pill-lock"><?= icon('lock', 12) ?><?= e((string)$s['visibility']) ?></span>
            <?php endif; ?>
          </p>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
