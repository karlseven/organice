<?php
/**
 * The media library, as one block of markup used in two places: the standalone
 * /media page, and the picker modal the editor opens.
 *
 * It ships EMPTY and is filled by media.js from /api/media. Rendering the grid
 * server-side would mean two implementations of the same list — one in PHP for
 * the first paint and one in JavaScript for every folder change after it —
 * which is exactly the kind of duplication that drifts.
 *
 * @var array<string,mixed> $space
 * @var bool $picker  true inside the editor modal: shows Insert, hides nothing else
 */
$picker = $picker ?? false;
?>
<div class="media" data-media data-space="<?= (int)$space['id'] ?>"<?= $picker ? ' data-picker="1"' : '' ?>>

  <div class="media-bar">
    <?php /* Breadcrumb, built by media.js — the root crumb is always present so
             there is a way back even before anything has loaded. */ ?>
    <nav class="media-crumbs" data-media-crumbs aria-label="<?= e(t('media.folder_nav')) ?>"></nav>

    <div class="media-bar-right">
      <label class="media-search">
        <span class="visually-hidden"><?= e(t('media.search')) ?></span>
        <input type="search" data-media-search placeholder="<?= e(t('media.search')) ?>"
               data-tip="<?= e(t('media.search_tip')) ?>">
      </label>

      <button class="btn btn-ghost btn-sm" type="button" data-media-newfolder
              data-tip="<?= e(t('media.new_folder_tip')) ?>">
        <?= icon('folder-plus', 14) ?> <?= e(t('media.new_folder')) ?>
      </button>

      <button class="btn btn-sm" type="button" data-media-upload
              data-tip="<?= e(t('media.upload_tip')) ?>">
        <?= icon('upload', 14) ?> <?= e(t('media.upload')) ?>
      </button>
      <input type="file" data-media-file multiple hidden
             accept="image/*,application/pdf,.zip,.txt,.md,.csv">
    </div>
  </div>

  <?php /* One live region for both states. A grid that is empty because the
           folder is empty and one that is empty because a search matched
           nothing need different words, and saying neither leaves the reader
           looking at a blank rectangle. */ ?>
  <p class="media-note muted" data-media-note role="status" hidden></p>

  <div class="media-grid" data-media-grid
       aria-label="<?= e(t('media.grid')) ?>"
       aria-busy="true"></div>

  <div class="media-foot">
    <span class="muted" data-media-count></span>
    <?php if ($picker): ?>
      <button class="btn btn-sm" type="button" data-media-insert disabled><?= e(t('media.insert')) ?></button>
    <?php endif; ?>
  </div>
</div>
