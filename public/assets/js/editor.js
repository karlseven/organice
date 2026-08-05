/* organice — the editor.
 *
 * Markdown in, Markdown out. Everything below treats the textarea's value as
 * the single source of truth for the document.
 *
 * A rich text mode was built over this and then removed — see docs/EDITOR.md.
 * The contract it needed still holds if anyone tries again: a `getValue` /
 * `setValue` pair over the same string, and nothing else here changes.
 */
(function () {
  'use strict';

  var ED = window.ED;
  if (!ED) return;

  var url = function (p) { return ED.base + p; };
  var T = ED.t || {};
  var str = function (k, fb) { return T[k] || fb; };
  // every request carries the language being edited; the URL prefix is the
  // READING language and is not the same thing
  var q = function (p) { return url(p) + (p.indexOf('?') === -1 ? '?' : '&') + 'lang=' + encodeURIComponent(ED.lang); };

  var $ = function (s) { return document.querySelector(s); };
  var content  = $('#ed-content');
  var titleIn  = $('#ed-title');
  var slugIn   = $('#ed-slug');
  var statusIn = $('#ed-status');
  var preview  = $('#ed-preview');
  var stateEl  = document.querySelector('[data-save-state]');
  var fileIn   = $('#ed-file');
  var panes    = document.querySelector('[data-panes]');

  var dirty = false;
  var saving = false;

  // ---------------------------------------------------------------------------
  // live preview
  // ---------------------------------------------------------------------------
  var previewTimer = null;
  var previewSeq = 0;

  function renderPreview() {
    var mine = ++previewSeq;
    fetch(url('/api/preview'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ED.token },
      body: JSON.stringify({ content: content.value })
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        // a stale response must not overwrite a newer render
        if (!d || mine !== previewSeq) return;

        /* Which tab is open in each group, so it survives the rebuild below.
           Read here rather than before the fetch: the author may well have
           switched tabs while the request was in flight. */
        var tabs = window.Tabs ? window.Tabs.capture(preview) : null;

        /* Rendered by the same parser the published page uses, which escapes
           all author input — the preview cannot show something the real page
           would not. */
        preview.innerHTML = d.html;

        /* Every keystroke replaces the markup above, which resets each tab
           group to its first tab. Editing the second tab of a group while
           watching it snap back to the first on every character is unusable. */
        if (tabs) window.Tabs.restore(preview, tabs);
      })
      .catch(function () { /* a failed preview is not worth interrupting typing */ });
  }

  function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(renderPreview, 220);
  }

  content.addEventListener('input', function () {
    markDirty();
    histNote();
    schedulePreview();
  });
  renderPreview();

  // ---------------------------------------------------------------------------
  // undo / redo
  // ---------------------------------------------------------------------------
  /*
   * An explicit history, rather than leaning on the browser's.
   *
   * Native undo covers typing and nothing else here. Several things legitimately
   * assign content.value outright — swapping an upload placeholder for its real
   * link, restoring a revision, dropping in a machine translation — and every
   * such assignment discards the browser's undo stack silently, taking the
   * author's earlier typing with it. Ctrl+Z then does nothing, with no way to
   * tell which edit killed it.
   *
   * So the editor keeps its own stack and handles both keys itself. That also
   * makes redo consistent: Ctrl+Y is redo in Chrome but not in Firefox, where
   * only Ctrl+Shift+Z is. Both work here.
   */
  var HIST_MAX = 300;
  var hist = [{ v: content.value, s: content.selectionStart, e: content.selectionEnd }];
  var histAt = 0;
  var histTimer = null;
  var applying = false;

  /* Typing is coalesced: a snapshot per keystroke would mean 400 presses of
     Ctrl+Z to undo a paragraph. A pause of 400ms ends the step. */
  function histNote() {
    if (applying) return;
    clearTimeout(histTimer);
    histTimer = setTimeout(histCommit, 400);
  }

  function histCommit() {
    clearTimeout(histTimer);
    histTimer = null;
    if (content.value === hist[histAt].v) return;
    hist.length = histAt + 1;          // a fresh edit discards the redo tail
    hist.push({ v: content.value, s: content.selectionStart, e: content.selectionEnd });
    if (hist.length > HIST_MAX) hist.shift();
    histAt = hist.length - 1;
  }

  function histApply(step) {
    histCommit();                      // half-typed words are a step of their own
    var to = histAt + step;
    if (to < 0 || to >= hist.length) return;
    histAt = to;

    applying = true;
    content.value = hist[to].v;
    content.setSelectionRange(hist[to].s, hist[to].e);
    applying = false;

    content.focus();
    markDirty();
    schedulePreview();
  }

  [titleIn, slugIn, statusIn].forEach(function (el) {
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
  });

  /* Typing a title updates the slug only while the page still has its
     generated one. Once someone edits the slug by hand it is theirs — a URL
     silently changing under an author is how published links break. */
  var slugTouched = false;
  slugIn.addEventListener('input', function () { slugTouched = true; });
  titleIn.addEventListener('input', function () {
    if (slugTouched) return;
    slugIn.value = titleIn.value.toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
  });

  function markDirty() {
    dirty = true;
    setState(T.unsaved);
  }

  function setState(text) {
    if (stateEl) stateEl.textContent = text;
  }

  // ---------------------------------------------------------------------------
  // save
  // ---------------------------------------------------------------------------
  function save() {
    if (saving) return;
    saving = true;
    setState(T.saving);

    fetch(q('/api/pages/' + ED.pageId), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ED.token },
      body: JSON.stringify({
        lang:    ED.lang,
        title:   titleIn.value,
        slug:    slugIn.value,
        status:  statusIn.value,
        // language-neutral, so it is sent whichever translation is being edited
        icon:    iconBtn ? (iconBtn.dataset.icon || '') : '',
        content: content.value
      })
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        saving = false;
        if (!res.ok) throw new Error(res.d.message || 'Save failed');
        dirty = false;
        /* The server may have adjusted the slug to keep it unique among
           siblings; show what it actually saved rather than what was typed. */
        if (res.d.slug) slugIn.value = res.d.slug;
        setState(T.saved);
        setTimeout(function () { if (!dirty) setState(''); }, 2500);
      })
      .catch(function (err) {
        saving = false;
        setState(T.notSaved);
        Dialog.alert(err.message, { title: str('failed', 'That did not work') });
      });
  }

  document.querySelector('[data-save]').addEventListener('click', save);

  // ---------------------------------------------------------------------------
  // delete this page
  // ---------------------------------------------------------------------------
  var delBtn = document.querySelector('[data-delete]');
  if (delBtn) delBtn.addEventListener('click', function () {
    var kids = Number(delBtn.dataset.kids || 0);

    /* The subpage count is named in the question, not discovered afterwards.
       Deleting a page takes its whole subtree with it (ON DELETE CASCADE on
       pages.parent_id), and "delete 1 page" reading as "delete 12 pages" is
       exactly the surprise a confirm dialog exists to prevent. */
    var msg = kids
      ? fill(str('deleteKids', 'Delete ":title" and its :n subpages? This cannot be undone.'),
             { ':title': delBtn.dataset.title, ':n': kids })
      : fill(str('deleteAsk', 'Delete ":title"? This cannot be undone.'),
             { ':title': delBtn.dataset.title });

    Dialog.confirm(msg, {
      title: str('deleteTitle', 'Delete this page?'),
      okLabel: str('deleteLabel', 'Delete'),
      danger: true
    }).then(function (ok) {
      if (!ok) return;

      delBtn.disabled = true;
      fetch(q('/api/pages/' + ED.pageId + '/delete'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ED.token }
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.d.message || 'Could not delete the page');
          /* Cleared before navigating, or the beforeunload guard below asks the
             author to save changes to a page that no longer exists. */
          dirty = false;
          window.location.href = res.d.redirect;
        })
        .catch(function (err) {
          delBtn.disabled = false;
          Dialog.alert(err.message, { title: str('failed', 'That did not work') });
        });
    });
  });

  function fill(s, vars) {
    Object.keys(vars).forEach(function (k) { s = s.split(k).join(vars[k]); });
    return s;
  }

  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
      ev.preventDefault();
      save();
    }
  });

  window.addEventListener('beforeunload', function (ev) {
    if (!dirty) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // ---------------------------------------------------------------------------
  // formatting toolbar
  // ---------------------------------------------------------------------------
  var WRAP = {
    bold:   ['**', '**', 'bold text'],
    italic: ['_', '_', 'italic text'],
    code:   ['`', '`', 'code']
  };
  var PREFIX = {
    h2:    '## ',
    ul:    '- ',
    ol:    '1. ',
    quote: '> '
  };

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-md]');
    if (!btn) return;
    var kind = btn.dataset.md;

    if (WRAP[kind])   return wrap(WRAP[kind][0], WRAP[kind][1], WRAP[kind][2]);
    if (PREFIX[kind]) return prefixLines(PREFIX[kind]);

    if (kind === 'link') return insertLink();
    if (kind === 'fence')   return block('```\n' + (selection() || 'code') + '\n```');
    if (kind === 'callout') return block(':::info\n' + (selection() || 'Something worth noticing.') + '\n:::');
    if (kind === 'table')   return block('| Column | Column |\n| --- | --- |\n| Value | Value |');
    if (kind === 'tabs')    return block(':::tabs\n=== First\n\n=== Second\n\n:::');
  });

  // ---------------------------------------------------------------------------
  // colour
  // ---------------------------------------------------------------------------
  /*
   * Three things share one popover, because they are the same question asked
   * about different targets: the letters, the space behind them, and the table
   * cell they sit in. A table cell cannot be expressed inline — {bg:red}(x)
   * would tint only as far as the last letter — so it gets its own form,
   * a marker at the start of the cell.
   */
  var colWrap = document.querySelector('[data-colour-wrap]');
  if (colWrap) {
    var colBtn = colWrap.querySelector('[data-colour-toggle]');
    var colPop = colWrap.querySelector('[data-colour-pop]');

    /* Every existing colour marker, for the "remove colour" action. Written
       once here so the stripper and the parser cannot disagree about the
       syntax. */
    var TX_RE   = /\{(?:bg:)?[a-z]{3,10}\}\(([^()]*)\)/g;
    var CELL_RE = /^\s*\{bg:[a-z]{3,10}\}\s*/;

    var closeCol = function () {
      colPop.hidden = true;
      colBtn.setAttribute('aria-expanded', 'false');
    };

    colBtn.addEventListener('click', function () {
      var open = colPop.hidden;
      colPop.hidden = !open;
      colBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (ev) {
      if (!colWrap.contains(ev.target)) closeCol();
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') closeCol();
    });

    colPop.addEventListener('click', function (ev) {
      var sw = ev.target.closest('[data-colour], [data-colour-bg], [data-colour-cell]');
      if (!sw && !ev.target.closest('[data-colour-clear]')) return;
      closeCol();

      if (ev.target.closest('[data-colour-clear]')) return stripColour();

      if (sw.dataset.colour)    return paint('{' + sw.dataset.colour + '}(', ')');
      if (sw.dataset.colourBg)  return paint('{bg:' + sw.dataset.colourBg + '}(', ')');
      return cellColour(sw.dataset.colourCell);
    });

    /* Re-colouring replaces the existing marker instead of nesting a second one
       — {red}({blue}(x)) is not something the parser accepts, and it is what
       you get if picking a colour twice just wraps again. */
    function paint(open, close) {
      var s = content.selectionStart, e = content.selectionEnd;
      var text = content.value.slice(s, e);
      if (!text) return wrap(open, close, 'text');

      text = text.replace(TX_RE, '$1');
      edit(s, e, open + text + close);
      histCommit();
      markDirty();
      schedulePreview();
    }

    function stripColour() {
      var s = content.selectionStart, e = content.selectionEnd;
      if (s === e) return;
      var text = content.value.slice(s, e).replace(TX_RE, '$1');
      edit(s, e, text);
      histCommit();
      markDirty();
      schedulePreview();
    }

    /*
     * Shade the table cell the caret is in.
     *
     * The cell is found by scanning the current LINE between pipes, which is
     * what a Markdown table row is. Outside a table there is no cell to shade,
     * so this says so rather than inserting a marker that would render as
     * literal text in a paragraph.
     */
    function cellColour(colour) {
      var v = content.value;
      var caret = content.selectionStart;
      var from = v.lastIndexOf('\n', caret - 1) + 1;
      var to = v.indexOf('\n', caret);
      if (to === -1) to = v.length;

      var line = v.slice(from, to);
      if (line.indexOf('|') === -1) {
        Dialog.alert(str('colourNotCell', 'Put the caret inside a table cell first.'),
                     { title: str('colourCell', 'Table cell colour') });
        return;
      }

      // cell boundaries around the caret, within this row
      var start = v.lastIndexOf('|', caret - 1);
      var end = v.indexOf('|', caret);
      if (start < from || end === -1 || end > to) {
        Dialog.alert(str('colourNotCell', 'Put the caret inside a table cell first.'),
                     { title: str('colourCell', 'Table cell colour') });
        return;
      }

      /* The cell's own leading space is measured BEFORE the old marker is
         stripped. Taking it afterwards loses it — CELL_RE eats the whitespace
         along with the marker — so every re-colour would pull the text one
         space closer to the pipe and quietly mangle the author's formatting. */
      var cell = v.slice(start + 1, end);
      var lead = cell.match(/^\s*/)[0];
      var body = cell.slice(lead.length).replace(/^\{bg:[a-z]{3,10}\}\s*/, '');

      edit(start + 1, end, lead + '{bg:' + colour + '}' + body);
      histCommit();
      markDirty();
      schedulePreview();
    }
  }

  // ---------------------------------------------------------------------------
  // language: switching, machine translation, source pane
  // ---------------------------------------------------------------------------
  // ---------------------------------------------------------------------------
  // page icon
  // ---------------------------------------------------------------------------
  /* Held on the button's dataset rather than in a hidden input: there is no
     form here, and the button is already the thing that displays it. */
  var iconBtn = document.getElementById('ed-icon');
  if (iconBtn && window.Symbols) {
    iconBtn.addEventListener('click', function () {
      /* icons:true — this field stores a name, so the bundled vector set is on
         offer here. Symbols.preview builds the markup, because a chosen icon is
         an <svg> and assigning it as textContent would print "lucide:rocket". */
      window.Symbols.open(iconBtn, function (ch) {
        iconBtn.dataset.icon = ch;
        window.Symbols.preview(iconBtn, ch, 20);
        iconBtn.classList.toggle('has-icon', ch !== '');
        markDirty();
      }, { icons: true });
    });
  }

  var langSel = document.getElementById('ed-lang');
  if (langSel) {
    langSel.addEventListener('change', function () {
      var go = function () {
        /* A full reload rather than swapping the text in place. Each language
           has its own title, slug state, status and history, and re-deriving
           that client-side would be a second copy of the server's rules. */
        dirty = false;
        window.location.href = url('/edit/' + ED.pageId + '?lang=' + encodeURIComponent(langSel.value));
      };

      if (!dirty) return go();

      Dialog.confirm(str('discardAsk', 'You have unsaved changes. Discard them?'), {
        title: str('discardTitle', 'Unsaved changes'),
        okLabel: str('discardOk', 'Discard'),
        danger: true
      }).then(function (ok) {
        if (ok) go(); else langSel.value = ED.lang;
      });
    });
  }

  var translateBtn = document.querySelector('[data-translate]');
  if (translateBtn) {
    translateBtn.addEventListener('click', function () {
      // only worth asking when there is something to lose
      var ask = content.value.trim() === ''
        ? Promise.resolve(true)
        : Dialog.confirm(str('translateAsk', 'This replaces what is currently in the editor. Continue?'), {
            title: str('translateTitle', 'Replace this translation?'),
            okLabel: str('translateOk', 'Translate'),
            danger: true
          });

      ask.then(function (go) {
        if (!go) return;
        runTranslate();
      });
    });

    function runTranslate() {
      translateBtn.disabled = true;
      setState(T.translating);

      fetch(url('/api/pages/' + ED.pageId + '/translate'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ED.token },
        body: JSON.stringify({ lang: ED.lang })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          translateBtn.disabled = false;
          if (!res.ok) throw new Error(res.d.message || 'Translation failed');
          content.value = res.d.content;
          histCommit();
          titleIn.value = res.d.title;
          /* Left as a draft and marked dirty on purpose — machine output is a
             starting point for a person, not something to publish unread. */
          statusIn.value = 'draft';
          setState(T.translated);
          renderPreview();
        })
        .catch(function (err) {
          translateBtn.disabled = false;
          setState('');
          Dialog.alert(err.message, { title: str('failed', 'That did not work') });
        });
    }
  }

  var sourceToggle = document.querySelector('[data-source-toggle]');
  var sourcePane = document.querySelector('[data-source-pane]');
  if (sourceToggle && sourcePane) {
    sourceToggle.addEventListener('click', function () {
      sourcePane.hidden = !sourcePane.hidden;
      preview.hidden = !sourcePane.hidden;
    });
  }

  function selection() {
    return content.value.slice(content.selectionStart, content.selectionEnd);
  }

  /**
   * Ask for a URL and wrap the selection in a link.
   *
   * The selection has to be captured BEFORE the dialog opens: opening it moves
   * focus out of the textarea, and in some browsers that collapses the
   * selection, so reading it afterwards inserts an empty link.
   */
  function insertLink() {
    var sel = selection();
    var start = content.selectionStart;
    var end = content.selectionEnd;

    Dialog.prompt(str('linkLabel', 'Link URL'), {
      title: str('linkTitle', 'Insert link'),
      value: 'https://',
      placeholder: 'https://example.com'
    }).then(function (href) {
      if (!href) { content.focus(); return; }
      content.focus();
      content.selectionStart = start;
      content.selectionEnd = end;
      replace('[' + (sel || str('linkText', 'link text')) + '](' + href + ')');
    });
  }

  /**
   * Replace a range of the textarea, KEEPING the browser's undo history.
   *
   * This is the whole reason the toolbar edits go through one function.
   * setRangeText() and assigning .value both write the field behind the
   * browser's back: the native undo stack is discarded, so Ctrl+Z after using
   * any toolbar button did nothing at all — and worse, it also threw away the
   * undo history for everything typed before it.
   *
   * execCommand('insertText') is the one way to change a textarea that the
   * browser records as a user edit. It is deprecated on paper and implemented
   * everywhere in practice; there is no replacement that preserves undo. The
   * fallback keeps the editor working if it ever does disappear — losing undo
   * is much better than losing the button.
   */
  function edit(start, end, text) {
    content.focus();
    content.setSelectionRange(start, end);

    var ok = false;
    try {
      ok = document.execCommand('insertText', false, text);
    } catch (e) { ok = false; }

    if (!ok) {
      content.setRangeText(text, start, end, 'end');
      content.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function replace(text, caretOffset) {
    var s = content.selectionStart;
    var e = content.selectionEnd;
    edit(s, e, text);
    if (typeof caretOffset === 'number') {
      content.selectionStart = content.selectionEnd = s + caretOffset;
    }
    content.focus();
    markDirty();
    schedulePreview();
  }

  function wrap(open, close, placeholder) {
    var sel = selection();
    var body = sel || placeholder;
    var s = content.selectionStart;
    edit(s, content.selectionEnd, open + body + close);
    // with nothing selected, leave the placeholder selected so it can be typed over
    if (!sel) {
      content.selectionStart = s + open.length;
      content.selectionEnd = s + open.length + body.length;
    }
    content.focus();
    markDirty();
    schedulePreview();
  }

  function prefixLines(prefix) {
    var v = content.value;
    var s = v.lastIndexOf('\n', content.selectionStart - 1) + 1;
    var e = v.indexOf('\n', content.selectionEnd);
    if (e === -1) e = v.length;

    var lines = v.slice(s, e).split('\n').map(function (l, i) {
      // an ordered list has to count, or every item comes out as "1."
      var p = prefix === '1. ' ? (i + 1) + '. ' : prefix;
      return l.startsWith(p) ? l.slice(p.length) : p + l;   // second press removes it
    });

    edit(s, e, lines.join('\n'));
    content.focus();
    markDirty();
    schedulePreview();
  }

  function block(text) {
    var s = content.selectionStart;
    var before = s > 0 && content.value[s - 1] !== '\n' ? '\n\n' : '';
    replace(before + text + '\n');
  }

  // Ctrl+B / Ctrl+I / Ctrl+K while writing
  content.addEventListener('keydown', function (ev) {
    if (!(ev.ctrlKey || ev.metaKey)) return;
    var k = ev.key.toLowerCase();
    if (k === 'z' && !ev.shiftKey) { ev.preventDefault(); histApply(-1); return; }
    if (k === 'y' || (k === 'z' && ev.shiftKey)) { ev.preventDefault(); histApply(1); return; }
    if (k === 'b') { ev.preventDefault(); wrap('**', '**', 'bold text'); }
    if (k === 'i') { ev.preventDefault(); wrap('_', '_', 'italic text'); }
    if (k === 'k') { ev.preventDefault(); insertLink(); }
  });

  // Tab indents instead of leaving the textarea — nested lists are unusable
  // otherwise. Escape first restores tab's normal behaviour for keyboard users.
  var tabTraps = true;
  content.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { tabTraps = false; return; }
    if (ev.key !== 'Tab' || !tabTraps) return;
    ev.preventDefault();
    replace('  ');
  });
  content.addEventListener('focus', function () { tabTraps = true; });

  // ---------------------------------------------------------------------------
  // uploads: button, paste, drag and drop
  // ---------------------------------------------------------------------------
  document.querySelector('[data-upload]').addEventListener('click', function () { fileIn.click(); });

  // ---------------------------------------------------------------------------
  // media library picker
  // ---------------------------------------------------------------------------
  /*
   * Opens the same library markup the /media page uses, inside a dialog, and
   * drops the chosen file's Markdown at the caret.
   *
   * The markup is fetched rather than duplicated in this file: one definition
   * of a grid, a folder tree and their labels, translated once. It is fetched
   * on FIRST OPEN, not at page load — an author who never reuses an image
   * should not pay for a library they did not ask for.
   */
  var libBtn = document.querySelector('[data-library]');
  var libHtml = null;

  if (libBtn) libBtn.addEventListener('click', function () {
    libBtn.disabled = true;

    /* Where the caret is RIGHT NOW, before a modal dialog takes focus.
       showModal() moves focus into the dialog, and by the time something is
       chosen the textarea's selection has collapsed to 0 — so the image lands
       at the top of the document instead of where the author was working. */
    var at = { s: content.selectionStart, e: content.selectionEnd };

    var got = libHtml
      ? Promise.resolve(libHtml)
      : fetch(url('/media?space=' + ED.spaceId + '&partial=1'), { headers: { 'Accept': 'text/html' } })
          .then(function (r) {
            if (!r.ok) throw new Error(str('mediaFail', 'The library could not be opened'));
            return r.text();
          })
          .then(function (html) { libHtml = html; return html; });

    got
      .then(function (html) {
        libBtn.disabled = false;

        /* Parsed into a node before it goes anywhere near the dialog:
           Dialog.panel takes a node, not a string, so that no future caller can
           pass it a filename. <template> parses inert — no image starts
           loading, no script would run — until it is adopted below. */
        var tpl = document.createElement('template');
        tpl.innerHTML = html;

        var lib = tpl.content.querySelector('[data-media]');
        if (!lib) throw new Error(str('mediaFail', 'The library could not be opened'));
        lib.dataset.picker = '1';

        var dlg = Dialog.panel({
          title: str('mediaTitle', 'Media library'),
          node: lib,
          wide: true
        });

        /* Inserted through edit(), like every other toolbar action, so the
           insertion is one undo step rather than a change Ctrl+Z cannot see. */
        lib.__onInsert = function (md) {
          dlg.close();
          content.focus();
          content.setSelectionRange(at.s, at.e);
          var before = at.s > 0 && content.value[at.s - 1] !== '\n' ? '\n\n' : '';
          edit(at.s, at.e, before + md + '\n');
          histCommit();
          markDirty();
          schedulePreview();
        };

        Media.mount(lib);
      })
      .catch(function (err) {
        libBtn.disabled = false;
        Dialog.alert(err.message, { title: str('failed', 'That did not work') });
      });
  });
  fileIn.addEventListener('change', function () {
    if (fileIn.files[0]) upload(fileIn.files[0]);
    fileIn.value = '';
  });

  content.addEventListener('paste', function (ev) {
    var items = (ev.clipboardData || {}).items || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind !== 'file') continue;
      var f = items[i].getAsFile();
      if (!f) continue;
      ev.preventDefault();
      upload(f);
      return;
    }
  });

  ['dragover', 'drop'].forEach(function (type) {
    content.addEventListener(type, function (ev) {
      ev.preventDefault();
      if (type === 'drop' && ev.dataTransfer.files[0]) upload(ev.dataTransfer.files[0]);
    });
  });

  function upload(file) {
    /* A placeholder goes in immediately and is swapped for the real link when
       the upload lands: on a slow connection an author otherwise cannot tell
       whether the paste registered, and pastes it again. */
    var token = '![uploading ' + file.name + '…]()';
    replace(token);
    setState(T.uploading);

    var body = new FormData();
    body.append('file', file);
    body.append('space_id', String(ED.spaceId));
    body.append('_token', ED.token);

    fetch(url('/api/upload'), { method: 'POST', headers: { 'X-CSRF-Token': ED.token }, body: body })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.d.message || 'Upload failed');
        content.value = content.value.replace(token, res.d.markdown);
        histCommit();
        setState(T.unsaved);
        schedulePreview();
      })
      .catch(function (err) {
        content.value = content.value.replace(token, '');
        histCommit();
        setState(T.uploadFail);
        Dialog.alert(err.message, { title: str('uploadFail', 'Upload failed') });
      });
  }

  // ---------------------------------------------------------------------------
  // revision history
  // ---------------------------------------------------------------------------
  var panel = document.querySelector('[data-history-panel]');
  var list  = document.querySelector('[data-history-list]');

  document.querySelector('[data-history]').addEventListener('click', function () {
    panel.hidden = false;
    list.innerHTML = '<li class="muted">…</li>';

    fetch(q('/api/pages/' + ED.pageId + '/revisions'), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.revisions.length) { list.innerHTML = '<li class="muted">' + esc(T.noHistory) + '</li>'; return; }
        list.innerHTML = d.revisions.map(function (r) {
          return '<li>' +
            '<div class="rev-when">' + esc(r.when) + (r.current ? ' · ' + esc(T.current) : '') + (r.machine ? ' · ' + esc(T.machine) : '') + '</div>' +
            '<div class="rev-meta">' + esc(r.author) + ' · ' + r.size + ' chars' +
              (r.summary ? ' · ' + esc(r.summary) : '') + '</div>' +
            (r.current ? '' :
              '<div class="rev-actions">' +
                '<button class="btn btn-ghost btn-sm" data-rev-diff="' + r.id + '">' + esc(T.changes) + '</button>' +
                '<button class="btn btn-ghost btn-sm" data-rev-load="' + r.id + '">' + esc(T.preview) + '</button>' +
                '<button class="btn btn-sm" data-rev-restore="' + r.id + '">' + esc(T.restore) + '</button>' +
              '</div>') +
            '<div class="rev-diff" data-diff-for="' + r.id + '" hidden></div>' +
            '</li>';
        }).join('');
      });
  });

  document.querySelector('[data-history-close]').addEventListener('click', function () {
    panel.hidden = true;
  });

  // "Changes": diff this revision against the current one
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-rev-diff]');
    if (!btn) return;

    var id = btn.dataset.revDiff;
    var box = document.querySelector('[data-diff-for="' + id + '"]');
    if (!box.hidden) { box.hidden = true; return; }   // second press closes it

    box.hidden = false;
    box.textContent = 'Loading…';

    fetch(q('/api/pages/' + ED.pageId + '/diff?from=' + encodeURIComponent(id)), {
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) throw new Error(d.message);
        if (!d.rows.length) { box.textContent = T.noChanges; return; }

        var head = '<p class="diff-stat"><span class="diff-add">+' + d.added +
          '</span> <span class="diff-del">-' + d.removed + '</span>' +
          (d.truncated ? ' <span class="muted">(truncated)</span>' : '') + '</p>';

        box.innerHTML = head + '<table class="diff">' + d.rows.map(function (r) {
          if (r.t === 'gap') return '<tr class="d-gap"><td colspan="3">⋯</td></tr>';
          var sign = r.t === 'add' ? '+' : (r.t === 'del' ? '-' : ' ');
          return '<tr class="d-' + r.t + '">' +
            '<td class="d-num">' + (r.o == null ? '' : r.o) + '</td>' +
            '<td class="d-num">' + (r.n == null ? '' : r.n) + '</td>' +
            '<td class="d-txt">' + esc(sign + ' ' + r.x) + '</td></tr>';
        }).join('') + '</table>';
      })
      .catch(function (err) { box.textContent = err.message || 'Could not load the diff.'; });
  });

  document.addEventListener('click', function (ev) {
    var load = ev.target.closest('[data-rev-load]');
    if (load) {
      fetch(url('/api/revisions/' + load.dataset.revLoad), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          content.value = d.content;
          histCommit();
          titleIn.value = d.title;
          markDirty();          // loaded, not saved — the author still decides
          renderPreview();
        });
      return;
    }

    var restore = ev.target.closest('[data-rev-restore]');
    if (!restore) return;

    Dialog.confirm(T.restoreAsk, {
      title: str('restoreTitle', 'Restore this revision?'),
      okLabel: T.restore
    }).then(function (ok) {
      if (!ok) return;

      fetch(q('/api/pages/' + ED.pageId + '/revert/' + restore.dataset.revRestore), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ED.token }
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          content.value = d.content;
          histCommit();
          titleIn.value = d.title;
          dirty = false;
          setState(str('restored', 'Restored'));
          panel.hidden = true;
          renderPreview();
        });
    });
  });

  // ---------------------------------------------------------------------------
  // pane splitter + mobile mode switch
  // ---------------------------------------------------------------------------
  var split = document.querySelector('[data-split]');
  var dragging = false;

  split.addEventListener('mousedown', function () { dragging = true; document.body.style.userSelect = 'none'; });
  window.addEventListener('mouseup', function () { dragging = false; document.body.style.userSelect = ''; });
  window.addEventListener('mousemove', function (ev) {
    if (!dragging) return;
    var pct = Math.min(80, Math.max(20, (ev.clientX / window.innerWidth) * 100));
    panes.children[0].style.flex = '0 0 ' + pct + '%';
    panes.children[2].style.flex = '1';
  });

  // ---------------------------------------------------------------------------
  // markdown / preview
  // ---------------------------------------------------------------------------
  /* On a wide screen both panes are already side by side, so this only moves
     focus. On a narrow one the panes are stacked and only one is shown, and this
     is what swaps them. */
  document.addEventListener("click", function (ev) {
    var btn = ev.target.closest("[data-mode]");
    if (!btn || btn.disabled) return;
    document.querySelectorAll("[data-mode]").forEach(function (b) {
      b.classList.toggle("active", b === btn);
    });
    panes.classList.toggle("show-preview", btn.dataset.mode === "preview");
    if (btn.dataset.mode === "markdown") content.focus();
  });
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
