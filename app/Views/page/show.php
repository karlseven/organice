<?php
/**
 * @var array<string,mixed> $space
 * @var array<string,mixed> $page
 * @var array<int,array<string,mixed>> $tree
 * @var array<int,array<string,mixed>> $crumbs
 * @var array<int,array{level:int,text:string,id:string}> $toc
 * @var string $html
 * @var string $pageTitle
 * @var bool   $canEdit, $isFallback, $isStale, $isMachine
 */
use Core\I18n;
use Core\Site;

css_add('.space-dot { background: ' . preg_replace('/[^#0-9a-f]/i', '', (string)$space['accent']) . '; }');

$sourceName = I18n::endonym(I18n::defaultLang());
$thisName   = I18n::endonym(I18n::current());
?>
<div class="doc-shell">

  <?= \Core\View::partial('partials/sidebar', [
        'tree' => $tree, 'space' => $space, 'page' => $page,
        'crumbs' => $crumbs, 'canEdit' => $canEdit,
      ]) ?>

  <article class="doc-body">
    <nav class="crumbs" aria-label="<?= e(t('page.breadcrumb')) ?>">
      <a href="<?= e(Site::spaceUrl((string)$space['slug'])) ?>"><?= e((string)$space['title']) ?></a>
      <?php foreach ($crumbs as $c): ?>
        <span class="sep">/</span>
        <a href="<?= e(Site::pageUrl((string)$space['slug'], (string)$c['path'])) ?>"><?= e((string)$c['title']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="doc-head">
      <?php /* Sized to the heading rather than the sidebar's 16px. Core\Icon
               escapes the literal form and returns markup for the vector one,
               so its return value is printed raw on purpose. */
            $headIcon = Core\Icon::page((string)($page['icon'] ?? ''), 34); ?>
      <h1><?php if ($headIcon !== ''): ?><span class="page-icon" aria-hidden="true"><?= $headIcon ?></span><?php endif; ?><?= e($pageTitle) ?></h1>
      <?php if ($canEdit): ?>
        <a class="btn btn-sm" href="<?= e(url('/edit/' . $page['id'] . '?lang=' . I18n::current())) ?>">
          <?= icon('edit', 14) ?> <?= e(t('page.edit')) ?>
        </a>
      <?php endif; ?>
    </div>

    <?php /* ---- translation state ----------------------------------------
       Three separate conditions, each said plainly, because they mean
       genuinely different things to a reader: this is not your language / a
       machine wrote this / this is behind the original. Silently showing
       stale or machine text as though it were the real thing is the failure
       mode that makes multilingual docs worse than none. */ ?>

    <?php if ($isFallback): ?>
      <div class="callout callout-info notice-lang" role="note">
        <p class="callout-title"><?= e(t('lang.fallback_title', ['language' => $thisName])) ?></p>
        <p><?= e(t('lang.fallback_note', ['source' => $sourceName])) ?></p>
      </div>
    <?php else: ?>
      <?php if ($isMachine): ?>
        <div class="callout callout-warning notice-lang" role="note">
          <p class="callout-title"><?= icon('info', 14) ?> <?= e(t('lang.machine_title')) ?></p>
          <p><?= e(t('lang.machine_note')) ?></p>
          <p><a href="<?= e(I18n::swapUrl($selfPath, I18n::defaultLang())) ?>">
            <?= e(t('lang.view_source', ['source' => $sourceName])) ?></a></p>
        </div>
      <?php endif; ?>

      <?php if ($isStale): ?>
        <div class="callout callout-warning notice-lang" role="note">
          <p class="callout-title"><?= e(t('lang.stale_title')) ?></p>
          <p><?= e(t('lang.stale_note', ['source' => $sourceName])) ?></p>
          <p><a href="<?= e(I18n::swapUrl($selfPath, I18n::defaultLang())) ?>">
            <?= e(t('lang.view_source', ['source' => $sourceName])) ?></a></p>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($page['status'] === 'draft'): ?>
      <div class="callout callout-warning" role="note">
        <p class="callout-title"><?= e(t('page.draft')) ?></p>
        <p><?= e(t('page.draft_note')) ?></p>
      </div>
    <?php endif; ?>

    <div class="prose">
      <?php if (trim($html) === ''): ?>
        <p class="muted"><?= e($canEdit ? t('page.empty_edit') : t('page.empty')) ?></p>
      <?php else: ?>
        <?= $html /* rendered by Core\Markdown, which escapes all author input */ ?>
      <?php endif; ?>
    </div>

    <footer class="doc-foot">
      <?php if (!empty($page['revised_at'])): ?>
        <p class="muted last-edit">
          <?= e($page['revised_by']
                ? t('page.updated_by', ['when' => ago((string)$page['revised_at']), 'who' => (string)$page['revised_by']])
                : t('page.updated',    ['when' => ago((string)$page['revised_at'])])) ?>
        </p>
      <?php endif; ?>

      <nav class="pager" aria-label="<?= e(t('page.pager')) ?>">
        <?php if ($prev): ?>
          <a class="pager-link prev" href="<?= e(Site::pageUrl((string)$space['slug'], (string)$prev['path'])) ?>">
            <span class="pager-label"><?= e(t('page.previous')) ?></span>
            <span class="pager-title"><?= e((string)$prev['title']) ?></span>
          </a>
        <?php else: ?><span></span><?php endif; ?>

        <?php if ($next): ?>
          <a class="pager-link next" href="<?= e(Site::pageUrl((string)$space['slug'], (string)$next['path'])) ?>">
            <span class="pager-label"><?= e(t('page.next')) ?></span>
            <span class="pager-title"><?= e((string)$next['title']) ?></span>
          </a>
        <?php endif; ?>
      </nav>
    </footer>
  </article>

  <?php if ($toc !== []): ?>
    <aside class="toc" aria-label="<?= e(t('page.on_this_page')) ?>">
      <p class="toc-head"><?= e(t('page.on_this_page')) ?></p>
      <ul>
        <?php foreach ($toc as $tItem): ?>
          <li class="toc-l<?= (int)$tItem['level'] ?>">
            <a href="#<?= e($tItem['id']) ?>" data-toc-link><?= e($tItem['text']) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>
  <?php endif; ?>

</div>
