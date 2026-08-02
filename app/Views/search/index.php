<?php
/**
 * @var string $q
 * @var array<int,array<string,mixed>> $results
 */
?>
<div class="home search-page">
  <h1><?= e(t('search.title')) ?></h1>

  <form class="search-full" action="<?= e(lurl('/search')) ?>" method="get" role="search">
    <?= icon('search', 18) ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('nav.search')) ?>"
           aria-label="<?= e(t('nav.search_label')) ?>" autofocus>
    <button class="btn" type="submit"><?= e(t('search.button')) ?></button>
  </form>

  <?php if ($q === ''): ?>
    <p class="muted"><?= e(t('search.prompt')) ?></p>
  <?php elseif ($results === []): ?>
    <div class="empty-state">
      <p><?= e(t('search.none', ['query' => $q])) ?></p>
      <p class="muted"><?= e(t('search.none_hint')) ?></p>
    </div>
  <?php else: ?>
    <p class="muted"><?= e(count($results) === 1 ? t('search.count_one') : t('search.count_many', ['count' => count($results)])) ?></p>
    <ul class="results">
      <?php foreach ($results as $r): ?>
        <li>
          <a href="<?= e(Core\Site::pageUrl((string)$r['space_slug'], (string)$r['path'])) ?>">
            <span class="result-space"><?= e((string)$r['space_title']) ?></span>
            <span class="result-title"><?= e((string)$r['title']) ?></span>
          </a>
          <p class="result-excerpt"><?= e(mb_substr((string)$r['excerpt'], 0, 220)) ?>…</p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
