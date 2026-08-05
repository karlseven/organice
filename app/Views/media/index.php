<?php
/**
 * The standalone media library.
 *
 * @var array<int,array<string,mixed>> $spaces  every space this person can write
 * @var array<string,mixed> $space              the one being browsed
 */
?>
<div class="home media-page">
  <div class="media-head">
    <h1><?= e(t('media.title')) ?></h1>

    <?php if (count($spaces) > 1): ?>
      <?php /* A GET form, not a fetch: switching space reloads the page, so the
               URL says which library you are looking at and Back does the
               obvious thing.

               No onchange="" attribute — `script-src` carries a nonce and no
               'unsafe-inline', so an inline handler is refused silently and the
               control would simply do nothing. media.js submits on change, and
               the button is what makes it work without JavaScript at all. */ ?>
      <form method="get" action="<?= e(url('/media')) ?>" class="media-spacepick" data-media-spaceform>
        <label class="visually-hidden" for="media-space"><?= e(t('media.space')) ?></label>
        <span class="ed-select" data-tip="<?= e(t('media.space')) ?>">
          <select id="media-space" name="space">
            <?php foreach ($spaces as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$space['id'] ? 'selected' : '' ?>>
                <?= e((string)$s['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </span>
        <button class="btn btn-ghost btn-sm" type="submit" data-media-spacego><?= e(t('media.space_go')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <p class="muted media-intro"><?= e(t('media.intro')) ?></p>

  <?php require APP_PATH . '/Views/partials/media-library.php'; ?>
</div>
