<?php
/**
 * The editor. Rendered by View::bare — it owns the whole viewport, so it
 * carries its own document shell rather than the site layout.
 *
 * @var array<string,mixed> $page
 * @var array<string,mixed> $space
 * @var array<int,array<string,mixed>> $flat
 * @var array<int,array<string,mixed>> $locales
 * @var array<string,mixed>|null $source
 * @var string $token, $lang
 * @var bool $canTranslate
 */
use Core\I18n;

$written = array_column($locales, null, 'lang');
$isDefaultLang = $lang === I18n::defaultLang();
?><!doctype html>
<html lang="<?= e(I18n::current()) ?>" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/tooltip.js')) ?>" defer></script>
<script src="<?= e(asset('js/dialog.js')) ?>" defer></script>
<script src="<?= e(asset('js/symbols.js')) ?>" defer></script>
<?php /* The reader script, so the PREVIEW behaves like the published page:
          tab strips switch, code blocks get their copy button, details toggle.
          Without it the preview rendered tabs but clicking one did nothing —
          the handlers all live here. It is written as progressive enhancement
          over elements that may not exist, so the parts that belong to the
          reading chrome (search, sidebar, table of contents) simply no-op. */ ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/editor.js')) ?>" defer></script>
</head>
<body class="editor-body">

<header class="editor-bar">
  <a class="icon-btn" href="<?= e(Core\Site::pageUrl((string)$space['slug'], (string)$page['path'], $lang)) ?>"
     aria-label="<?= e(t('editor.back')) ?>" data-tip="<?= e(t('editor.back')) ?>">
    <?= icon('close') ?>
  </a>

  <?php /* The page icon is a field of its own, not part of the title — see the
            comment on pages.icon. It is language-neutral, so it is edited here
            once rather than per translation. */ ?>
  <button class="icon-picker<?= ($page['icon'] ?? '') !== '' ? ' has-icon' : '' ?>"
          type="button" id="ed-icon" data-icon="<?= e((string)($page['icon'] ?? '')) ?>"
          data-tip="<?= e(t('editor.tip_icon')) ?>"
          aria-label="<?= e(t('editor.tip_icon')) ?>"><?= ($edIcon = Core\Icon::page((string)($page['icon'] ?? ''), 20)) !== ''
            ? $edIcon : '<span class="muted">+</span>' ?></button>

  <div class="editor-titles">
    <?php /* No data-symbols here on purpose. The title had a symbol button, but
              the page ICON now has its own picker immediately to the left (with
              an emoji tab), so a second one three centimetres away was offering
              the same thing twice — and when the bar wrapped it stranded a lone
              ☺ at the start of the toolbar row, looking like a control that had
              lost its label. Authors who want an emoji IN a title can still
              paste or use the OS emoji key. */ ?>
    <input class="editor-title" id="ed-title" type="text"
           value="<?= e((string)($page['locale_title'] ?? $page['title'])) ?>"
           aria-label="<?= e(t('editor.title')) ?>" placeholder="<?= e(t('editor.title')) ?>">
    <div class="editor-slug">
      <span class="muted"><?= e(Core\Site::isSingle() && $space['slug'] === Core\Site::singleSlug()
              ? I18n::prefix($lang) . '/'
              : I18n::prefix($lang) . '/s/' . $space['slug'] . '/') ?></span>
      <input id="ed-slug" type="text" value="<?= e((string)$page['slug']) ?>"
             aria-label="<?= e(t('editor.slug')) ?>" spellcheck="false">
    </div>
  </div>

  <div class="editor-actions">
    <?php /* The language being EDITED — separate from the interface language.
              A translator is usually reading English while writing Thai. */ ?>
    <?php /* data-tip as well as aria-label. The two are not interchangeable:
              aria-label is what a screen reader announces, data-tip is what a
              sighted user sees on hover. A control that shows only "English"
              gives no clue that it means the language being EDITED rather than
              the interface language. */ ?>
    <label class="ed-lang ed-select" data-tip="<?= e(t('editor.tip_language')) ?>">
      <span class="visually-hidden"><?= e(t('editor.language')) ?></span>
      <select id="ed-lang" aria-label="<?= e(t('editor.language')) ?>">
        <?php foreach (I18n::enabled() as $code): ?>
          <option value="<?= e($code) ?>" <?= $code === $lang ? 'selected' : '' ?>>
            <?= e(I18n::endonym($code)) ?><?php
              if (!isset($written[$code])) echo ' · ' . t('lang.untranslated');
              elseif (($written[$code]['source'] ?? '') === 'machine') echo ' · ' . t('lang.machine_title');
            ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php if (!$isDefaultLang && $canTranslate): ?>
      <button class="btn btn-ghost btn-sm" type="button" data-translate data-tip="<?= e(t('lang.tip_translate')) ?>">
        <?= e(t('lang.translate')) ?>
      </button>
    <?php endif; ?>

    <?php /* Markdown or preview. There is no rich text mode — see docs/EDITOR.md
              for why it was built and then removed. On a wide screen both panes
              are already side by side and this only moves focus; on a narrow one
              it swaps which pane you see. */ ?>
    <div class="seg" role="group" aria-label="<?= e(t('editor.view')) ?>">
      <button class="seg-btn active" type="button" data-mode="markdown" data-tip="<?= e(t('editor.tip_markdown')) ?>"><?= e(t('editor.markdown')) ?></button>
      <button class="seg-btn" type="button" data-mode="preview" data-tip="<?= e(t('editor.tip_preview')) ?>"><?= e(t('editor.preview')) ?></button>
    </div>

    <?php /* The wrapper exists so the custom dropdown arrow has something to
              attach to — a <select> cannot carry a ::after of its own. */ ?>
    <span class="ed-select" data-tip="<?= e(t('editor.tip_status')) ?>">
      <select id="ed-status" aria-label="<?= e(t('editor.status')) ?>">
        <option value="draft"     <?= ($page['locale_status'] ?? $page['status']) === 'draft' ? 'selected' : '' ?>><?= e(t('editor.draft')) ?></option>
        <option value="published" <?= ($page['locale_status'] ?? $page['status']) === 'published' ? 'selected' : '' ?>><?= e(t('editor.published')) ?></option>
      </select>
    </span>

    <button class="btn btn-ghost btn-sm" type="button" data-history data-tip="<?= e(t('editor.tip_history')) ?>"><?= icon('history', 14) ?> <?= e(t('editor.history')) ?></button>
    <button class="btn btn-sm" type="button" data-save data-tip="<?= e(t('editor.tip_save')) ?>"><?= e(t('editor.save')) ?></button>
    <span class="save-state" data-save-state aria-live="polite"></span>
  </div>
</header>

<div class="editor-panes" data-panes>
  <section class="pane pane-write">
    <?php /* Icons, not lone characters. "•", "1.", "”", "{}", "!", "▤" and "▦"
              were each meant to suggest what the button did, and together they
              read as a row of punctuation — you had to hover every one to find
              out. Bold and Italic keep their letterforms because a bold B and a
              slanted I ARE the convention, and nothing is clearer. */ ?>
    <div class="ed-toolbar" role="toolbar" aria-label="<?= e(t('editor.formatting')) ?>">
      <button type="button" data-md="bold"    data-tip="<?= e(t('editor.tip_bold')) ?>" aria-label="<?= e(t('editor.tip_bold')) ?>"><strong>B</strong></button>
      <button type="button" data-md="italic"  data-tip="<?= e(t('editor.tip_italic')) ?>" aria-label="<?= e(t('editor.tip_italic')) ?>"><em>I</em></button>
      <button type="button" data-md="h2"      data-tip="<?= e(t('editor.tip_heading')) ?>" aria-label="<?= e(t('editor.tip_heading')) ?>"><?= icon('heading-2', 15) ?></button>
      <span class="ed-toolbar-sep"></span>
      <button type="button" data-md="ul"      data-tip="<?= e(t('editor.tip_ul')) ?>" aria-label="<?= e(t('editor.tip_ul')) ?>"><?= icon('list', 15) ?></button>
      <button type="button" data-md="ol"      data-tip="<?= e(t('editor.tip_ol')) ?>" aria-label="<?= e(t('editor.tip_ol')) ?>"><?= icon('list-ordered', 15) ?></button>
      <button type="button" data-md="quote"   data-tip="<?= e(t('editor.tip_quote')) ?>" aria-label="<?= e(t('editor.tip_quote')) ?>"><?= icon('text-quote', 15) ?></button>
      <span class="ed-toolbar-sep"></span>
      <button type="button" data-md="link"    data-tip="<?= e(t('editor.tip_link')) ?>" aria-label="<?= e(t('editor.tip_link')) ?>"><?= icon('link', 15) ?></button>
      <button type="button" data-md="code"    data-tip="<?= e(t('editor.tip_code')) ?>" aria-label="<?= e(t('editor.tip_code')) ?>"><?= icon('code', 15) ?></button>
      <button type="button" data-md="fence"   data-tip="<?= e(t('editor.tip_fence')) ?>" aria-label="<?= e(t('editor.tip_fence')) ?>"><?= icon('square-code', 15) ?></button>
      <span class="ed-toolbar-sep"></span>
      <button type="button" data-md="callout" data-tip="<?= e(t('editor.tip_callout')) ?>" aria-label="<?= e(t('editor.tip_callout')) ?>"><?= icon('info', 15) ?></button>
      <button type="button" data-md="tabs"    data-tip="<?= e(t('editor.tip_tabs')) ?>" aria-label="<?= e(t('editor.tip_tabs')) ?>"><?= icon('panels-top-left', 15) ?></button>
      <button type="button" data-md="table"   data-tip="<?= e(t('editor.tip_table')) ?>" aria-label="<?= e(t('editor.tip_table')) ?>"><?= icon('table', 15) ?></button>
      <span class="ed-toolbar-spacer"></span>
      <?php if ($source !== null): ?>
        <button type="button" data-source-toggle data-tip="<?= e(t('lang.view_source', ['source' => I18n::endonym(I18n::defaultLang())])) ?>">
          <?= icon('eye', 14) ?> <?= e(strtoupper(I18n::defaultLang())) ?>
        </button>
      <?php endif; ?>
      <button type="button" data-upload data-tip="<?= e(t('editor.tip_image')) ?>"><?= icon('image', 14) ?></button>
    </div>

    <textarea id="ed-content" spellcheck="true" aria-label="<?= e(t('editor.content')) ?>"
      placeholder="<?= e(t('editor.placeholder')) ?>"><?= e((string)($page['content_md'] ?? '')) ?></textarea>

    <input type="file" id="ed-file" accept="image/*,application/pdf" hidden>
  </section>

  <div class="pane-split" data-split role="separator" aria-label="Resize panes" tabindex="0"></div>

  <section class="pane pane-preview">
    <?php if ($source !== null): ?>
      <?php /* The source text sits beside the translation rather than in another
                window. Read-only: editing English here would silently fork it. */ ?>
      <div class="source-pane" data-source-pane hidden>
        <p class="source-head"><?= e(I18n::endonym(I18n::defaultLang())) ?></p>
        <pre class="source-text"><?= e((string)($source['content_md'] ?? '')) ?></pre>
      </div>
    <?php endif; ?>
    <div class="prose" id="ed-preview" aria-live="polite"></div>
  </section>
</div>

<aside class="history-panel" data-history-panel hidden aria-label="<?= e(t('editor.history')) ?>">
  <header>
    <h2><?= e(t('editor.history')) ?></h2>
    <button class="icon-btn" type="button" data-history-close aria-label="Close"><?= icon('close') ?></button>
  </header>
  <ul data-history-list></ul>
</aside>

<script nonce="<?= e(CSP_NONCE) ?>">
/* app.js and symbols.js both read APP_BASE; the site layout defines it and this
   bare view did not, so under a subdirectory install they built URLs from the
   domain root. */
window.APP_BASE = <?= json_encode(APP_BASE) ?>;
window.ED = {
  base:    <?= json_encode(APP_BASE) ?>,
  pageId:  <?= (int)$page['id'] ?>,
  spaceId: <?= (int)$space['id'] ?>,
  lang:    <?= json_encode($lang) ?>,
  token:   <?= json_encode($token) ?>,
  t: <?= json_encode([
        'saving'      => t('editor.saving'),
        'saved'       => t('editor.saved'),
        'unsaved'     => t('editor.unsaved'),
        'notSaved'    => t('editor.not_saved'),
        'uploading'   => t('editor.uploading'),
        'uploadFail'  => t('editor.upload_fail'),
        'noHistory'   => t('editor.no_history'),
        'noChanges'   => t('editor.no_changes'),
        'changes'     => t('editor.changes'),
        'preview'     => t('editor.preview'),
        'restore'     => t('editor.restore'),
        'restoreAsk'  => t('editor.restore_ask'),
        'current'     => t('editor.current'),
        'machine'     => t('lang.machine_title'),
        'translating' => t('lang.translating'),
        'translated'  => t('lang.translated'),
        'chars'       => t('editor.chars', ['count' => '%d']),
        // dialog chrome — the editor loads dialog.js too
        'dlgOk'        => t('dialog.ok'),
        'dlgCancel'    => t('dialog.cancel'),
        'dlgConfirm'   => t('dialog.confirm'),
        'dlgNotice'    => t('dialog.notice'),
        'failed'       => t('dialog.failed'),
        'confirmTitle' => t('dialog.confirm_title'),
        // editor-specific prompts
        'linkTitle'      => t('editor.link_title'),
        'linkLabel'      => t('editor.link_label'),
        'linkText'       => t('editor.link_text'),
        'discardTitle'   => t('editor.discard_title'),
        'discardAsk'     => t('editor.discard_ask'),
        'discardOk'      => t('dialog.discard'),
        'restoreTitle'   => t('editor.restore_title'),
        'restored'       => t('editor.restored'),
        'translateTitle' => t('lang.translate_title'),
        'translateAsk'   => t('lang.translate_ask'),
        'translateOk'    => t('lang.translate'),
        'uploadFail'     => t('editor.upload_fail'),
        // symbol picker (the editor loads symbols.js too)
        'symbolsTitle'  => t('symbols.title'),
        'symbolsSearch' => t('symbols.search'),
        'symbolsNone'   => t('symbols.none'),
        'symbolsNone2'  => t('symbols.empty'),
        'symbolsTip'    => t('symbols.tip'),
        'symGroup_status' => t('sym.status'),
        'symGroup_docs'   => t('sym.docs'),
        'symGroup_tech'   => t('sym.tech'),
        'symGroup_arrows' => t('sym.arrows'),
        'symGroup_keys'   => t('sym.keys'),
        'symGroup_math'   => t('sym.math'),
        'symGroup_money'  => t('sym.money'),
        'symGroup_text'   => t('sym.text'),
        'symGroup_people' => t('sym.people'),
      ], JSON_UNESCAPED_UNICODE) ?>
};
</script>
</body>
</html>
