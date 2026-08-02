<?php
/**
 * @var array<int,array<string,mixed>> $spaces
 */
use Core\Brand;
use Core\Csrf;
use Core\I18n;
use Core\Settings;

$mode    = Settings::get('site_mode', 'multi');
$single  = Settings::get('single_space', '');
$enabled = I18n::enabled();
?>
<div class="home admin">
  <nav class="admin-tabs">
    <a href="<?= e(url('/admin')) ?>">Spaces</a>
    <a href="<?= e(url('/admin/users')) ?>">Users</a>
    <a class="active" href="<?= e(url('/admin/settings')) ?>">Settings</a>
    <a href="<?= e(url('/admin/audit')) ?>">Audit log</a>
  </nav>

  <h1>Settings</h1>

  <?php /* enctype is required — without it the browser posts filenames, not files */ ?>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <!-- ---------------------------------------------------------------- -->
    <section class="card">
      <h2>Identity</h2>
      <div class="grid-form">
        <label for="st-title">Site name</label>
        <input id="st-title" name="site_title" maxlength="120" data-symbols
               value="<?= e(Settings::get('site_title', SITE_NAME)) ?>">

        <label for="st-tagline">Tagline</label>
        <input id="st-tagline" name="site_tagline" maxlength="200" data-symbols
               value="<?= e(Settings::get('site_tagline', '')) ?>">

        <label for="st-footer">Footer note</label>
        <input id="st-footer" name="brand_footer" maxlength="300"
               placeholder="© Your Company"
               value="<?= e(Settings::get('brand_footer', '')) ?>">
      </div>
    </section>

    <!-- ---------------------------------------------------------------- -->
    <section class="card">
      <h2>Mode</h2>
      <p class="muted">
        Multi-space lists your books on the home page and serves pages at
        <code>/s/&lt;space&gt;/&lt;page&gt;</code>. Single-space makes one book
        <em>be</em> the site: the list disappears and pages live at
        <code>/&lt;page&gt;</code>. Old links keep working either way — they
        redirect.
      </p>
      <div class="grid-form">
        <label for="st-mode">Site mode</label>
        <select id="st-mode" name="site_mode">
          <option value="multi"  <?= $mode !== 'single' ? 'selected' : '' ?>>Multi-space</option>
          <option value="single" <?= $mode === 'single' ? 'selected' : '' ?>>Single space (focus mode)</option>
        </select>

        <label for="st-single">The space</label>
        <select id="st-single" name="single_space">
          <option value="">Choose a space…</option>
          <?php foreach ($spaces as $s): ?>
            <option value="<?= e((string)$s['slug']) ?>" <?= $single === $s['slug'] ? 'selected' : '' ?>>
              <?= e((string)$s['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </section>

    <!-- ---------------------------------------------------------------- -->
    <section class="card">
      <h2>Branding</h2>
      <div class="grid-form">
        <label>Logo</label>
        <div class="brand-slot">
          <?php if (Brand::has('brand_logo')): ?>
            <img class="brand-preview" src="<?= e(Brand::url('brand_logo')) ?>" alt="Current logo">
            <label class="brand-clear"><input type="checkbox" name="clear_brand_logo" value="1"> Remove</label>
          <?php else: ?>
            <span class="muted">Falling back to the site name</span>
          <?php endif; ?>
          <input type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        </div>

        <label>Logo (dark theme)</label>
        <div class="brand-slot">
          <?php if (Brand::has('brand_logo_dark')): ?>
            <img class="brand-preview on-dark" src="<?= e(Brand::url('brand_logo_dark')) ?>" alt="Current dark logo">
            <label class="brand-clear"><input type="checkbox" name="clear_brand_logo_dark" value="1"> Remove</label>
          <?php else: ?>
            <span class="muted">Optional — the light logo is used if this is empty</span>
          <?php endif; ?>
          <input type="file" name="brand_logo_dark" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        </div>

        <label>Favicon</label>
        <div class="brand-slot">
          <?php if (Brand::has('brand_favicon')): ?>
            <img class="brand-preview small" src="<?= e(Brand::url('brand_favicon')) ?>" alt="Current favicon">
            <label class="brand-clear"><input type="checkbox" name="clear_brand_favicon" value="1"> Remove</label>
          <?php endif; ?>
          <input type="file" name="brand_favicon" accept="image/png,image/x-icon,image/svg+xml">
        </div>

        <label for="st-accent">Accent colour</label>
        <div class="brand-slot">
          <input id="st-accent" name="brand_accent" type="color"
                 value="<?= e(Brand::accent() !== '' ? Brand::accent() : '#5b7cfa') ?>">
          <span class="muted">Links, buttons and highlights</span>
        </div>
      </div>
    </section>

    <!-- ---------------------------------------------------------------- -->
    <section class="card">
      <h2>Languages</h2>
      <div class="grid-form">
        <label for="st-lang">Default language</label>
        <select id="st-lang" name="default_lang">
          <?php foreach (I18n::LANGUAGES as $code => $meta): ?>
            <option value="<?= e($code) ?>" <?= I18n::defaultLang() === $code ? 'selected' : '' ?>>
              <?= e($meta['endonym']) ?> — <?= e($meta['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label>Available</label>
        <div class="lang-checks">
          <?php foreach (I18n::LANGUAGES as $code => $meta): ?>
            <label class="lang-check">
              <input type="checkbox" name="languages[]" value="<?= e($code) ?>"
                     <?= in_array($code, $enabled, true) ? 'checked' : '' ?>>
              <span lang="<?= e($code) ?>"><?= e($meta['endonym']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="muted">The default language is always available, whatever is ticked.</p>
    </section>

    <!-- ---------------------------------------------------------------- -->
    <section class="card">
      <h2>Machine translation</h2>
      <div class="grid-form">
        <label for="st-mt">Driver</label>
        <select id="st-mt" name="mt_driver">
          <option value=""               <?= Settings::get('mt_driver') === '' ? 'selected' : '' ?>>Off</option>
          <option value="google"         <?= Settings::get('mt_driver') === 'google' ? 'selected' : '' ?>>Google Cloud Translation (paid)</option>
          <option value="libretranslate" <?= Settings::get('mt_driver') === 'libretranslate' ? 'selected' : '' ?>>LibreTranslate (self-hosted)</option>
        </select>

        <label for="st-mtp">Non-public spaces</label>
        <select id="st-mtp" name="mt_allow_private">
          <option value="0" <?= Settings::get('mt_allow_private', '0') !== '1' ? 'selected' : '' ?>>Never send them out</option>
          <option value="1" <?= Settings::get('mt_allow_private', '0') === '1' ? 'selected' : '' ?>>Allow — I accept the disclosure</option>
        </select>
      </div>
      <p class="muted">
        The API key lives in <code>.env</code> as <code>MT_KEY</code>, not here —
        this table is readable by every admin.
        Translating sends page text to a third party.
      </p>
    </section>

    <div class="settings-actions">
      <button class="btn" type="submit">Save settings</button>
    </div>
  </form>
</div>
