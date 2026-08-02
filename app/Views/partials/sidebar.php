<?php
/**
 * The navigation tree. Rendered recursively; $tree is the nested structure
 * from Core\Tree::build and $space supplies the URL prefix.
 *
 * @var array<int,array<string,mixed>> $tree
 * @var array<string,mixed> $space
 * @var array<string,mixed>|null $page  the page being viewed, for highlighting
 */
$current = (int)($page['id'] ?? 0);
$openIds = [];
foreach ($crumbs ?? [] as $c) $openIds[(int)$c['id']] = true;

$canSort = !empty($canEdit);

$render = static function (array $nodes, int $depth) use (&$render, $space, $current, $openIds, $canSort): string {
    $out = '<ul class="nav-list nav-depth-' . $depth . '">';
    foreach ($nodes as $n) {
        $id       = (int)$n['id'];
        $active   = $id === $current;
        $hasKids  = $n['children'] !== [];
        /* A branch is open when the current page is inside it. Collapsing
           everything else is what keeps a large book navigable — an author with
           two hundred pages should not have to scroll past all of them. */
        $open     = $hasKids && (isset($openIds[$id]) || $active);
        $href     = Core\Site::pageUrl((string)$space['slug'], (string)$n['path']);

        /* The drag handler needs the page's current parent and position to work
           out where a drop lands; carrying them on the element avoids a second
           request just to ask the server what it already rendered. */
        $out .= '<li class="nav-item' . ($open ? ' open' : '') . ($hasKids ? ' has-children' : '') . '"'
              . ' data-page-id="' . $id . '"'
              . ' data-parent-id="' . (int)($n['parent_id'] ?? 0) . '"'
              . ' data-position="' . (int)($n['position'] ?? 0) . '">';
        $out .= '<div class="nav-row"' . ($canSort ? ' draggable="true"' : '') . '>';
        if ($hasKids) {
            $out .= '<button class="nav-caret" type="button" data-nav-caret aria-expanded="'
                  . ($open ? 'true' : 'false') . '" aria-label="Toggle section">' . icon('caretR', 14) . '</button>';
        }
        $out .= '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e($href) . '"'
              . ($active ? ' aria-current="page"' : '') . '>';
        /* aria-hidden: the icon is decoration. A screen reader announcing
           "rocket Getting started" for every entry is noise, and the icon
           carries no information the title does not. */
        /* Core\Icon escapes the literal-character form itself and returns markup
           for the vector form, so this must NOT be escaped again here. */
        $ic = Core\Icon::page((string)($n['icon'] ?? ''), 16);
        if ($ic !== '') {
            $out .= '<span class="page-icon" aria-hidden="true">' . $ic . '</span>';
        }
        $out .= e((string)$n['title']);
        if (($n['status'] ?? '') === 'draft') $out .= ' <span class="pill">' . e(t('page.draft')) . '</span>';
        /* Marks pages that have no version in the language being read, so a
           translator can see the gaps at a glance instead of opening each one. */
        if (empty($n['translated']) && !Core\I18n::isDefault()) {
            $out .= ' <span class="pill pill-untranslated" title="' . e(t('lang.untranslated')) . '">'
                  . e(strtoupper(Core\I18n::defaultLang())) . '</span>';
        }
        $out .= '</a></div>';

        if ($hasKids) $out .= $render($n['children'], $depth + 1);
        $out .= '</li>';
    }
    return $out . '</ul>';
};
?>
<aside class="sidebar" data-sidebar>
  <div class="sidebar-head">
    <a class="space-name" href="<?= e(Core\Site::spaceUrl((string)$space['slug'])) ?>">
      <span class="space-dot" data-accent></span>
      <?= e((string)$space['title']) ?>
    </a>
    <?php if ($space['visibility'] !== 'public'): ?>
      <span class="pill pill-lock"><?= icon('lock', 12) ?><?= e((string)$space['visibility']) ?></span>
    <?php endif; ?>
  </div>

  <nav class="nav-tree" aria-label="<?= e(t('nav.nav_label')) ?>" <?= $canSort ? 'data-sortable' : '' ?>>
    <?= $render($tree, 0) ?>
  </nav>

  <?php if ($canSort): ?>
    <div class="sidebar-foot">
      <button class="btn btn-ghost btn-sm" type="button"
              data-new-page data-space="<?= (int)$space['id'] ?>"><?= icon('plus', 14) ?> <?= e(t('page.new')) ?></button>
      <p class="sidebar-hint muted"><?= e(t('page.drag_hint')) ?></p>
    </div>
  <?php endif; ?>
</aside>
