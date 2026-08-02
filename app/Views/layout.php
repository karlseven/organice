<?php
/**
 * Site chrome. $content is the rendered template, set by Core\View::render.
 *
 * @var string $content
 * @var string $title
 */
use Core\Auth;
use Core\Csrf;
use Core\I18n;
use Core\Brand;
use Core\Settings;
use Core\Site;

$siteTitle = Settings::get('site_title', SITE_NAME);
$flashMsg  = $flash ?? flash();
$langs     = I18n::enabled();

/* The path to re-request when the language changes. Defaults to the site root
   so the switcher always goes somewhere sensible; page views override it with
   their own path so switching keeps the reader on the same page. */
$switchPath = $selfPath ?? '';
?><!doctype html>
<html lang="<?= e(I18n::current()) ?>" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title ?? $siteTitle) ?></title>
<?php
/* Description and social preview. `$description` is the page's own prose
   excerpt where there is one — see PageController — because a search result
   showing the site tagline for every page tells a searcher nothing about which
   page they found. */
$desc = trim((string)($description ?? Settings::get('site_tagline', '')));
$canonical = $switchPath !== '' ? I18n::swapUrl($switchPath, I18n::current()) : url('/');
?>
<?php if ($desc !== ''): ?>
<meta name="description" content="<?= e($desc) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= e($title ?? $siteTitle) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= e($siteTitle) ?>">
<meta property="og:locale" content="<?= e(I18n::current()) ?>">
<meta name="twitter:card" content="summary">
<?php /* Canonical points at THIS language's URL, not the default one: each
          translation is its own document, and collapsing them onto one
          canonical would ask search engines to drop every translation. */ ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<?php if (!empty($noindex)): ?>
<?php /* Drafts and fallback pages must not be indexed — a fallback URL indexed
          as Thai content would send Thai searchers to English text. */ ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php if (Brand::has('brand_favicon')): ?>
<link rel="icon" href="<?= e(Brand::url('brand_favicon')) ?>">
<?php endif; ?>
<?php
/* The accent is a single custom property override, emitted into the nonced
   style block — a style="" attribute would be silently dropped by the CSP. */
if (($accent = Brand::accent()) !== '') css_add(":root { --accent: $accent; }");
?>
<?php if (($css = css_out()) !== ''): ?>
<style nonce="<?= e(CSP_NONCE) ?>"><?= $css ?></style>
<?php endif; ?>
<?php
/* Tells search engines these URLs are the same document in different
   languages, so they index the right one per reader instead of treating the
   translations as duplicates of each other. */
foreach ($langs as $code):
    if ($switchPath === '') continue; ?>
<link rel="alternate" hreflang="<?= e($code) ?>" href="<?= e(I18n::swapUrl($switchPath, $code)) ?>">
<?php endforeach; ?>
<?php if ($switchPath !== ''): ?>
<link rel="alternate" hreflang="x-default" href="<?= e(I18n::swapUrl($switchPath, I18n::defaultLang())) ?>">
<?php endif; ?>
<script src="<?= e(asset('js/tooltip.js')) ?>" defer></script>
<script src="<?= e(asset('js/dialog.js')) ?>" defer></script>
<script src="<?= e(asset('js/symbols.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main"><?= e(t('nav.skip')) ?></a>

<header class="topbar">
  <button class="icon-btn nav-toggle" type="button" data-nav-toggle
          aria-label="<?= e(t('nav.menu')) ?>" data-tip="<?= e(t('nav.menu')) ?>" aria-expanded="false">
    <?= icon('menu') ?>
  </button>

  <a class="brand" href="<?= e(lurl('/')) ?>">
    <?php if (Brand::has('brand_logo')): ?>
      <?php /* Two logos when both are set, swapped by CSS rather than by script:
               a dark-theme reader must not see a flash of the light logo. */ ?>
      <img class="brand-logo only-light" src="<?= e(Brand::url('brand_logo')) ?>" alt="<?= e($siteTitle) ?>">
      <img class="brand-logo only-dark" src="<?= e(Brand::has('brand_logo_dark') ? Brand::url('brand_logo_dark') : Brand::url('brand_logo')) ?>" alt="<?= e($siteTitle) ?>">
    <?php else: ?>
      <?= icon('book', 20) ?><span><?= e($siteTitle) ?></span>
    <?php endif; ?>
  </a>

  <form class="searchbox" action="<?= e(lurl('/search')) ?>" method="get" role="search" data-search>
    <?= icon('search', 16) ?>
    <input type="search" name="q" placeholder="<?= e(t('nav.search')) ?>"
           value="<?= e($q ?? '') ?>" autocomplete="off" aria-label="<?= e(t('nav.search_label')) ?>">
    <kbd>/</kbd>
    <div class="search-results" data-search-results hidden></div>
  </form>

  <nav class="topbar-actions">
    <?php if (count($langs) > 1): ?>
      <div class="lang-menu" data-lang-menu>
        <button class="icon-btn lang-btn" type="button" data-lang-toggle
                aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('nav.language')) ?>" data-tip="<?= e(t('nav.language')) ?>">
          <?= icon('globe') ?><span class="lang-code"><?= e(strtoupper(I18n::current())) ?></span>
        </button>
        <ul class="lang-list" data-lang-list hidden>
          <?php foreach ($langs as $code): ?>
            <li>
              <?php /* The endonym, not the English name: a reader who needs the
                        Japanese option cannot necessarily read "Japanese". */ ?>
              <a href="<?= e($switchPath !== ''
                        ? I18n::swapUrl($switchPath, $code)
                        : url('/?setlang=' . $code)) ?>"
                 lang="<?= e($code) ?>"
                 <?= $code === I18n::current() ? 'aria-current="true" class="active"' : '' ?>>
                <?= e(I18n::endonym($code)) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <button class="icon-btn" type="button" data-theme-toggle aria-label="<?= e(t('nav.theme')) ?>" data-tip="<?= e(t('nav.theme')) ?>">
      <span class="only-light"><?= icon('moon') ?></span>
      <span class="only-dark"><?= icon('sun') ?></span>
    </button>

    <?php if (Auth::check()): ?>
      <?php if (Auth::isAdmin()): ?>
        <a class="icon-btn" href="<?= e(url('/admin')) ?>" aria-label="<?= e(t('nav.admin')) ?>" data-tip="<?= e(t('nav.admin')) ?>"><?= icon('settings') ?></a>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/logout')) ?>" class="inline">
        <?= Csrf::field() ?>
        <button class="icon-btn" type="submit" aria-label="<?= e(t('nav.signout')) ?>" data-tip="<?= e(t('nav.signout')) ?>"><?= icon('logout') ?></button>
      </form>
    <?php else: ?>
      <a class="btn btn-ghost" href="<?= e(url('/login')) ?>"><?= e(t('nav.signin')) ?></a>
    <?php endif; ?>
  </nav>
</header>

<?php if ($flashMsg): ?>
  <div class="flash flash-<?= e($flashMsg['kind']) ?>" role="status"><?= e($flashMsg['msg']) ?></div>
<?php endif; ?>

<main id="main"><?= $content ?></main>

<script nonce="<?= e(CSP_NONCE) ?>">
/* Strings the scripts need. Passed in rather than fetched, so app.js stays a
   static cacheable file with no language baked into it. */
window.APP_BASE = <?= json_encode(APP_BASE) ?>;
window.T = <?= json_encode([
    // code blocks
    'copy'       => t('common.copy'),
    'copied'     => t('common.copied'),
    'copyTip'    => t('common.copy_tip'),
    'copyManual' => t('common.copy_manual'),
    // search
    'noMatches'  => t('search.no_matches'),
    // new page dialog
    'newPage'            => t('page.new_prompt'),
    'newPageLabel'       => t('page.new_label'),
    'newPagePlaceholder' => t('page.new_placeholder'),
    // dialog chrome
    'dlgOk'        => t('dialog.ok'),
    'dlgCancel'    => t('dialog.cancel'),
    'dlgConfirm'   => t('dialog.confirm'),
    'dlgClose'     => t('dialog.close'),
    'dlgNotice'    => t('dialog.notice'),
    'failed'       => t('dialog.failed'),
    'confirmTitle' => t('dialog.confirm_title'),
    'deleteLabel'  => t('dialog.delete'),
    // symbol picker
    'symbolsTitle'  => t('symbols.title'),
    'symbolsSearch' => t('symbols.search'),
    'symbolsNone'   => t('symbols.none'),
    'symbolsNone2'  => t('symbols.empty'),
    'symbolsTip'    => t('symbols.tip'),
    'symbolsIcons'     => t('symbols.icons'),
    'symbolsEmoji'     => t('symbols.emoji'),
    'symbolsLoading'   => t('symbols.loading'),
    'symbolsIconsFail' => t('symbols.icons_fail'),
    'symbolsEmojiFail' => t('symbols.emoji_fail'),
    'symGroup_status' => t('sym.status'),
    'symGroup_docs'   => t('sym.docs'),
    'symGroup_tech'   => t('sym.tech'),
    'symGroup_arrows' => t('sym.arrows'),
    'symGroup_keys'   => t('sym.keys'),
    'symGroup_math'   => t('sym.math'),
    'symGroup_money'  => t('sym.money'),
    'symGroup_text'   => t('sym.text'),
    'symGroup_people' => t('sym.people'),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
</body>
</html>
