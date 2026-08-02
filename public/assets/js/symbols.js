/* organice — symbol, emoji and icon picker.
 *
 * Two jobs, one component:
 *
 *   1. Choosing a page's ICON (stored in pages.icon).
 *   2. Inserting a character into any text input or textarea at the caret.
 *
 * A page icon can be one of two different things, and the picker offers both
 * on separate tabs because they are not interchangeable:
 *
 *   ICONS   named vectors from the bundled Lucide set, stored as 'lucide:name'.
 *           Monochrome, and they inherit `color` — so an icon turns blue when
 *           its page is the active one, and a sidebar of them looks like one
 *           designed set. This is what GitBook-style sidebars actually use.
 *
 *   EMOJI   real Unicode characters, stored literally. Full colour and fixed;
 *           they cannot follow the accent and never look like a matched set,
 *           but they need no asset and render anywhere.
 *
 * The icons tab is offered ONLY where the target can store a name — the page
 * icon field. A text input holds text, so inserting 'lucide:rocket' into a
 * title would put that literal string in the title; the tab is hidden there and
 * only characters are offered.
 *
 * Historical note, because the reasoning here changed: this file used to argue
 * that icon fonts were disqualified outright, since a webfont maps its glyphs
 * to Private Use Area codepoints that render as blank boxes wherever the font
 * is missing. That is true of FONTS, and it is why nothing here stores a PUA
 * codepoint. It is not true of SVG, which is what the set is shipped as — the
 * markup carries the shape, so there is nothing to fail to load.
 *
 * Usage:
 *   <input data-symbols>                        picker button beside the field
 *   Symbols.open(anchorEl, onPick, {icons:true}) drives it directly
 *   Symbols.preview(el, storedValue)             renders a chosen value
 */
(function () {
  'use strict';

  function str(k, fb) { return (window.T && window.T[k]) || (window.ED && window.ED.t && window.ED.t[k]) || fb; }

  var SVGNS = 'http://www.w3.org/2000/svg';
  var PREFIX = 'lucide:';

  /*
   * Where the icon assets live, worked out from THIS script's own URL.
   *
   * The obvious approach — read a global the server set — was wrong, and wrong
   * in a way that only showed up in one place. `window.APP_BASE` is defined by
   * the site layout, but the editor is a BARE view that owns its whole document
   * and sets `window.ED.base` instead. So in the editor the global was
   * undefined, the base silently became '', and the fetch went to the domain
   * root: "Icons could not be loaded", but only for authors, and only on
   * installs not mounted at the root.
   *
   * document.currentScript is the script element being executed, available
   * during initial evaluation of any classic script including a deferred one.
   * Deriving from it means this file works under any mount — domain root, an
   * Apache Alias, a subdirectory — with nothing to configure and no global to
   * forget. The APP_BASE globals remain as a fallback for the impossible case.
   */
  var ICON_DIR = (function () {
    var self = document.currentScript && document.currentScript.src;
    if (self) {
      var m = self.match(/^(.*)\/js\/symbols\.js(?:$|[?#])/);
      if (m) return m[1] + '/icons/';
    }
    var b = window.APP_BASE || (window.ED && window.ED.base) || '';
    return b + '/assets/icons/';
  })();

  function spriteUrl() { return ICON_DIR + 'lucide.svg'; }
  function indexUrl()  { return ICON_DIR + 'lucide-index.json'; }
  function emojiUrl()  { return ICON_DIR + 'emoji.json'; }

  /*
   * Typographic characters, which the emoji file does not contain.
   *
   * These are not emoji — → ± € ⌘ — are ordinary text characters that happen to
   * be hard to type, and a docs author wants them for the same reason they want
   * an em dash. They live in the same flat list as the emoji and are searched
   * the same way; they simply come from here rather than from emoji.json.
   *
   * [character, name, extra search words]
   */
  var SYMBOLS = [
    ['→','right arrow','east next'], ['←','left arrow','west back previous'],
    ['↑','up arrow','north'], ['↓','down arrow','south'],
    ['↔','left right arrow','both'], ['↕','up down arrow'],
    ['⇒','double right arrow','implies then'], ['⇐','double left arrow'],
    ['⇔','double arrow','iff equivalent'], ['↗','up right arrow','increase'],
    ['↘','down right arrow','decrease'], ['↖','up left arrow'], ['↙','down left arrow'],
    ['⤴','arrow curving up'], ['⤵','arrow curving down'], ['↻','clockwise arrow','refresh retry reload'],
    ['↺','anticlockwise arrow','undo revert'],
    ['▶','right triangle','play'], ['◀','left triangle'], ['▲','up triangle'], ['▼','down triangle'],
    ['●','filled circle','dot bullet'], ['○','empty circle','dot ring'],
    ['◆','filled diamond'], ['◇','empty diamond'], ['■','filled square'], ['□','empty square'],
    ['★','filled star','favourite favorite'], ['☆','empty star'],
    ['✔','check mark','tick done yes ok'], ['✘','cross mark','no fail wrong'],
    ['✚','heavy plus'], ['✖','heavy multiply'],

    ['⌘','command key','mac cmd meta'], ['⌥','option key','alt mac'],
    ['⇧','shift key'], ['⌃','control key','ctrl'],
    ['⏎','return key','enter newline'], ['⌫','backspace key','delete back'],
    ['⌦','forward delete key'], ['⎋','escape key','esc'],
    ['⇥','tab key','indent'], ['␣','space key','blank'],
    ['⇪','caps lock key'], ['⌤','enter key'],

    ['±','plus minus','tolerance'], ['×','multiplication sign','times'],
    ['÷','division sign','divide'], ['≈','approximately equal','about roughly'],
    ['≠','not equal','different'], ['≤','less than or equal','at most'],
    ['≥','greater than or equal','at least'], ['∞','infinity','endless'],
    ['°','degree','temperature angle'], ['µ','micro sign'],
    ['½','one half'], ['⅓','one third'], ['¼','one quarter'], ['¾','three quarters'],
    ['√','square root'], ['∑','summation','sum sigma'], ['∏','product sign'],
    ['∆','increment','delta change'], ['π','pi'], ['Ω','ohm sign','omega resistance'],
    ['∅','empty set','null none'], ['∈','element of','member in'],
    ['·','middle dot','multiply separator'], ['‰','per mille'],

    ['$','dollar sign','usd currency money'], ['€','euro sign','eur currency money'],
    ['£','pound sign','gbp sterling currency money'], ['¥','yen sign','jpy currency money'],
    ['₩','won sign','krw korea currency money'], ['₫','dong sign','vnd vietnam currency money'],
    ['฿','baht sign','thb thailand currency money'], ['₹','rupee sign','inr india currency money'],
    ['₱','peso sign','php philippines currency money'], ['₽','ruble sign','rub currency money'],
    ['₦','naira sign','ngn currency money'], ['¢','cent sign','currency money'],
    ['₿','bitcoin sign','btc crypto currency money'],

    ['—','em dash','long dash punctuation'], ['–','en dash','range punctuation'],
    ['…','ellipsis','dots punctuation'], ['•','bullet','list punctuation'],
    ['‣','triangular bullet'], ['«','left guillemet','quote punctuation'],
    ['»','right guillemet','quote punctuation'],
    ['“','left double quote','punctuation'], ['”','right double quote','punctuation'],
    ['‘','left single quote','punctuation'], ['’','right single quote','apostrophe punctuation'],
    ['§','section sign','clause punctuation'], ['¶','pilcrow','paragraph punctuation'],
    ['†','dagger','footnote punctuation'], ['‡','double dagger','footnote punctuation'],
    ['©','copyright sign','legal'], ['®','registered sign','trademark legal'],
    ['™','trade mark sign','legal'], ['№','numero sign','number'],
    ['⌀','diameter sign'], ['⁂','asterism'],
  ];

  var panel = null;
  var onPick = null;
  var anchor = null;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  /*
   * Draw the whole grid, in one pass, always.
   *
   * Three attempts at drawing it incrementally have now failed the same way —
   * an IntersectionObserver that never fired, a scroll handler rooted on the
   * wrong element, and a two-stage draw whose tail timer is throttled whenever
   * the tab is not in the foreground. Each left a grid holding a few hundred of
   * two thousand entries and looking exactly like a complete list, which is the
   * one outcome a picker must never produce: you do not go looking for icons you
   * have no reason to think exist.
   *
   * One pass costs ~90 ms for 2000 entries, measured. That is a real cost and it
   * is worth it, because it cannot be partially right. The delay is covered by
   * prefetching on hover instead, which removes the fetch from the click path
   * and is where the waiting actually was.
   *
   * Returns a no-op cancel so callers need not care which strategy is in use.
   */
  function drawGrid(grid, rows, make) {
    var frag = document.createDocumentFragment();
    for (var i = 0; i < rows.length; i++) frag.appendChild(make(rows[i]));
    grid.textContent = '';
    grid.appendChild(frag);
    return function () {};
  }

  function spinner(label) {
    var wrap = el('p', 'sym-empty muted sym-loading');
    wrap.appendChild(el('span', 'sym-spinner'));
    wrap.appendChild(el('span', null, label));
    return wrap;
  }

  /**
   * Start fetching both lists before they are needed.
   *
   * Called on hover/focus of anything that opens the picker: by the time a
   * pointer has travelled to a button and pressed it, a fetch started on
   * entering it has usually finished, so the panel opens with data in hand.
   * Both are no-ops once started — the promises are cached.
   */
  function warm() {
    loadIcons().catch(function () {});
    loadEmoji().catch(function () {});
  }

  /* ---- the bundled icon set ------------------------------------------ */

  /* [[name, searchable words], …]. Fetched once per page load and kept — 2000
     entries is trivial in memory, and an author picking icons for several pages
     should not refetch 150 KB each time. */
  var ICONS = null;
  var iconsPromise = null;

  function loadIcons() {
    if (iconsPromise) return iconsPromise;
    iconsPromise = fetch(indexUrl(), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (obj) {
        ICONS = Object.keys(obj).map(function (k) { return [k, obj[k]]; });
        return ICONS;
      });
    return iconsPromise;
  }

  /* ---- the bundled emoji list ---------------------------------------- */

  /* [[char, name, search terms], …] — the full CLDR set, fetched once. Kept in
     its own file rather than inlined here because it is ~128 KB, and a reader
     who never opens the picker should not download it with the page. */
  var EMOJI = null;
  var emojiPromise = null;

  function loadEmoji() {
    if (emojiPromise) return emojiPromise;
    emojiPromise = fetch(emojiUrl(), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (list) {
        /* Emoji first in Unicode order, then the typographic characters.
           Unicode order runs smileys → people → animals → food → travel →
           objects → symbols → flags, so the list still reads in the order a
           phone keyboard would show, without needing category headings. */
        EMOJI = list.concat(SYMBOLS.map(function (s) {
          return [s[0], s[1], s[1] + ' ' + (s[2] || '')];
        }));
        return EMOJI;
      });
    return emojiPromise;
  }

  /* ---- searching ------------------------------------------------------ */

  /*
   * Word-PREFIX matching, not substring.
   *
   * A plain indexOf looks right until you try it: "cat" matched fifty-odd
   * emoji because it appears inside "intoxicated", "delicate" and "communicate",
   * so the one thing you asked for was buried in noise. Comparing against
   * ' ' + terms and searching for ' ' + token anchors each match to the start of
   * a word, which is what a phone keyboard does — "cat" still finds "cats", it
   * just stops finding "intoxicated".
   *
   * Every token must match, so "flag thai" narrows rather than widens.
   */
  function hit(terms, tokens) {
    var padded = ' ' + terms;
    for (var i = 0; i < tokens.length; i++) {
      if (padded.indexOf(' ' + tokens[i]) === -1) return false;
    }
    return true;
  }

  /*
   * How good a match is, lowest first. Without this the results come back in
   * Unicode order, so searching "rocket" listed three astronauts before 🚀 —
   * technically correct and obviously wrong. The sort is stable, so entries
   * scoring the same keep their natural order.
   */
  function score(name, q) {
    /* Icon names are dash-separated ("shield-check"), emoji names are spaced
       ("grinning face"). Normalising means one scorer serves both, and
       "shield" ranks shield-check above brick-wall-shield. */
    var n = name.replace(/-/g, ' ');
    if (n === q) return 0;
    if (n.indexOf(q) === 0) return 1;
    if ((' ' + n).indexOf(' ' + q) !== -1) return 2;
    return 3;
  }

  function rank(rows, q, nameAt) {
    return rows
      .map(function (row, i) { return [score(row[nameAt].toLowerCase(), q), i, row]; })
      .sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; })
      .map(function (x) { return x[2]; });
  }

  function iconSvg(name, size) {
    /* createElementNS, not innerHTML: <svg> and <use> are not HTML elements and
       an HTML parser silently produces unrendered nodes for them. */
    var svg = document.createElementNS(SVGNS, 'svg');
    svg.setAttribute('width', size);
    svg.setAttribute('height', size);
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS(SVGNS, 'use');
    use.setAttribute('href', spriteUrl() + '#i-' + name);
    svg.appendChild(use);
    return svg;
  }

  /**
   * Render a stored icon value into an element — used for the editor's button
   * so the chosen icon appears exactly as the page will render it.
   * @param {Element} host
   * @param {string}  value  'lucide:name', a character, or '' for none
   */
  function preview(host, value, size) {
    host.textContent = '';
    if (!value) {
      host.appendChild(el('span', 'muted', '+'));
      return;
    }
    if (value.indexOf(PREFIX) === 0) {
      host.appendChild(iconSvg(value.slice(PREFIX.length), size || 20));
    } else {
      host.textContent = value;
    }
  }

  function close() {
    if (!panel) return;
    panel.remove();
    panel = null;
    onPick = null;
    if (anchor && anchor.focus) anchor.focus();
    anchor = null;
  }

  function build(withIcons) {
    var p = el('div', 'sym' + (withIcons ? ' sym-wide' : ''));
    p.setAttribute('role', 'dialog');
    p.setAttribute('aria-label', str('symbolsTitle', 'Insert a symbol'));

    var head = el('div', 'sym-head');
    var search = el('input', 'sym-search');
    search.type = 'search';
    search.placeholder = str('symbolsSearch', 'Search symbols…');
    search.setAttribute('aria-label', str('symbolsSearch', 'Search symbols…'));
    head.appendChild(search);

    var clear = el('button', 'btn btn-ghost btn-sm sym-clear', str('symbolsNone', 'None'));
    clear.type = 'button';
    clear.addEventListener('click', function () { pick(''); });
    head.appendChild(clear);
    p.appendChild(head);

    /* ---- tabs (only when both kinds are on offer) ---- */
    var tabs = null;
    if (withIcons) {
      tabs = el('div', 'sym-tabs');
      tabs.setAttribute('role', 'tablist');
      [['icons', str('symbolsIcons', 'Icons')], ['emoji', str('symbolsEmoji', 'Emoji')]].forEach(function (t, i) {
        var b = el('button', 'sym-tab' + (i === 0 ? ' active' : ''), t[1]);
        b.type = 'button';
        b.dataset.pane = t[0];
        b.setAttribute('role', 'tab');
        b.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
        b.addEventListener('click', function () { showPane(t[0]); });
        tabs.appendChild(b);
      });
      p.appendChild(tabs);
    }

    var body = el('div', 'sym-body');
    p.appendChild(body);

    /* ---- emoji pane ----
       One flat grid, no category headings. The set is the full CLDR list and it
       is searchable by name and keyword, so headings would only be something to
       scroll past — and with ~2000 entries the headings scroll off screen
       anyway, which makes them worse than useless: a label you cannot see while
       looking at the things it labels. */
    var emojiPane = el('div', 'sym-pane sym-pane-emoji');
    var cancelEmojiDraw = null;
    var emojiStatus = spinner(str('symbolsLoading', 'Loading emoji…'));
    var emojiGrid = el('div', 'sym-grid');
    emojiPane.appendChild(emojiStatus);
    emojiPane.appendChild(emojiGrid);
    body.appendChild(emojiPane);

    function applyEmojiFilter(q) {
      if (!EMOJI) return;
      var hits;
      if (q === '') {
        hits = EMOJI;
      } else {
        var toks = q.split(/\s+/);
        hits = rank(EMOJI.filter(function (row) { return hit(row[2], toks); }), q, 1);
      }

      if (cancelEmojiDraw) cancelEmojiDraw();
      cancelEmojiDraw = drawGrid(emojiGrid, hits, function (row) {
        var b = el('button', 'sym-item', row[0]);
        b.type = 'button';
        /* The NAME is the accessible label and the tooltip. "★" announced as
           its Unicode name is not helpful; "filled star" is. */
        b.setAttribute('aria-label', row[1]);
        b.setAttribute('data-tip', row[1]);
        b.addEventListener('click', function () { pick(row[0]); });
        return b;
      });

      emojiStatus.hidden = hits.length > 0;
      emojiStatus.textContent = str('symbolsNone2', 'Nothing matches.');
    }
    p._applyEmojiFilter = applyEmojiFilter;

    /* Rendered on first VIEW, not on open.
       Building both grids up front cost ~90 ms to draw ~4000 buttons, half of
       which were on the tab you were not looking at. Deferring the hidden one
       halves the work before the panel appears, and the deferred half is then
       paid while you are reading a tab you just asked for. */
    var emojiReady = false;
    function ensureEmoji() {
      if (emojiReady) return;
      emojiReady = true;
      loadEmoji().then(function () {
        emojiStatus.hidden = true;
        applyEmojiFilter(search.value.trim().toLowerCase());
      }).catch(function (e) {
        emojiStatus.hidden = false;
        emojiStatus.textContent = str('symbolsEmojiFail', 'Emoji could not be loaded.');
        if (window.console) console.warn('emoji list failed:', emojiUrl(), e);
      });
    }

    /* ---- icons pane ---- */
    var iconsPane = null;
    var iconGrid = null;
    var iconStatus = null;
    var matches = [];
    var cancelIconDraw = null;

    if (withIcons) {
      iconsPane = el('div', 'sym-pane sym-pane-icons');
      iconStatus = spinner(str('symbolsLoading', 'Loading icons…'));
      iconGrid = el('div', 'sym-grid sym-grid-ico');
      iconsPane.appendChild(iconStatus);
      iconsPane.appendChild(iconGrid);
      body.appendChild(iconsPane);
      emojiPane.hidden = true;

      /* Every match is drawn at once, deliberately.
         The obvious worry is 2000 buttons each holding an <svg><use>, so it was
         measured rather than assumed: building and laying out the full set is
         ~75 ms in this browser, which nobody will notice on a panel they opened
         on purpose. Two attempts at drawing it lazily — an IntersectionObserver
         and then a scroll handler — each stopped after the first chunk in a way
         that looked exactly like a complete list, which is the worst possible
         failure for a picker: you simply never learn the other 1900 exist. A
         measurable 75 ms beats a bug that hides most of the feature. */
      function applyIconFilter(q) {
        if (!ICONS) return;
        if (q === '') {
          matches = ICONS;
        } else {
          var toks = q.split(/\s+/);
          matches = rank(ICONS.filter(function (row) { return hit(row[1], toks); }), q, 0);
        }

        if (cancelIconDraw) cancelIconDraw();
        cancelIconDraw = drawGrid(iconGrid, matches, function (row) {
          var name = row[0];
          var b = el('button', 'sym-item ico-item');
          b.type = 'button';
          b.dataset.name = name;
          /* The name IS the accessible label and the tooltip — an author
             hunting for "gauge" wants to know which one this is. */
          b.setAttribute('aria-label', name.replace(/-/g, ' '));
          b.setAttribute('data-tip', name);
          b.appendChild(iconSvg(name, 20));
          b.addEventListener('click', function () { pick(PREFIX + name); });
          return b;
        });

        iconStatus.hidden = matches.length > 0;
        iconStatus.textContent = str('symbolsNone2', 'Nothing matches.');
        body.scrollTop = 0;
      }
      p._applyIconFilter = applyIconFilter;

      p._ensureIcons = function () {
        if (p._iconsReady) return;
        p._iconsReady = true;
        loadIcons().then(function () {
          iconStatus.hidden = true;
          applyIconFilter(search.value.trim().toLowerCase());
        }).catch(function (e) {
          iconStatus.hidden = false;
          iconStatus.textContent = str('symbolsIconsFail', 'Icons could not be loaded.');
          /* The URL matters more than the error: every realistic cause here is a
             wrong path (bad mount, assets not deployed, build script not run),
             and "could not be loaded" alone sends you looking in the wrong place. */
          if (window.console) console.warn('icon index failed:', indexUrl(), e);
        });
      };
    }

    function showPane(which) {
      if (!tabs) return;
      emojiPane.hidden = which !== 'emoji';
      if (iconsPane) iconsPane.hidden = which !== 'icons';
      tabs.querySelectorAll('.sym-tab').forEach(function (b) {
        var on = b.dataset.pane === which;
        b.classList.toggle('active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (which === 'emoji') ensureEmoji();
      if (which === 'icons' && p._ensureIcons) p._ensureIcons();
      /* The pane being revealed may have been filtered before the current query
         was typed, so bring it up to date rather than showing a stale list. */
      runFilter();
      search.focus();
    }
    p._showPane = showPane;

    /* Only the pane you are looking at is filtered; the other is refiltered when
       you switch to it. Filtering both meant every keystroke rebuilt ~4000
       buttons, half of them invisible. */
    var filterTimer = null;
    function runFilter() {
      var q = search.value.trim().toLowerCase();
      if (!emojiPane.hidden) {
        ensureEmoji();
        applyEmojiFilter(q);
      } else if (iconsPane && !iconsPane.hidden && p._applyIconFilter) {
        p._applyIconFilter(q);
      }
    }
    p._runFilter = runFilter;

    /* Debounced: rebuilding a 2000-button grid on every keystroke made typing
       feel like it was catching. 90 ms is below the point a pause is noticed but
       long enough that a burst of typing renders once instead of six times. */
    search.addEventListener('input', function () {
      clearTimeout(filterTimer);
      filterTimer = setTimeout(runFilter, 90);
    });

    /* Draw whichever pane opens first, now. */
    if (withIcons) { if (p._ensureIcons) p._ensureIcons(); } else ensureEmoji();

    /* Arrow keys move through the grid, so this is usable without a mouse and
       without tabbing through hundreds of buttons. */
    p.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { ev.stopPropagation(); close(); return; }
      if (ev.key.indexOf('Arrow') !== 0) return;

      var pane = p.querySelector('.sym-pane:not([hidden])') || p;
      var items = Array.prototype.filter.call(pane.querySelectorAll('.sym-item'), function (b) { return !b.hidden; });
      var at = items.indexOf(document.activeElement);
      if (at === -1) return;

      ev.preventDefault();
      var grid = pane.querySelector('.sym-grid');
      var perRow = Math.max(1, Math.floor((grid ? grid.clientWidth : 240) / 40));
      var next = at;
      if (ev.key === 'ArrowRight') next = at + 1;
      if (ev.key === 'ArrowLeft')  next = at - 1;
      if (ev.key === 'ArrowDown')  next = at + perRow;
      if (ev.key === 'ArrowUp')    next = at - perRow;
      if (items[next]) items[next].focus();
    });

    return p;
  }

  function pick(ch) {
    var cb = onPick;
    close();
    if (cb) cb(ch);
  }

  function place(p, near) {
    var r = near.getBoundingClientRect();
    var pr = p.getBoundingClientRect();
    var gap = 6;

    var top = r.bottom + gap;
    if (top + pr.height > window.innerHeight - 8) {
      top = Math.max(8, r.top - pr.height - gap);
    }
    var left = Math.min(r.left, window.innerWidth - pr.width - 8);
    left = Math.max(8, left);

    // CSSOM, not a style attribute — see tooltip.js for why that is allowed
    p.style.top = Math.round(top + window.scrollY) + 'px';
    p.style.left = Math.round(left + window.scrollX) + 'px';
  }

  /**
   * @param {Element}  near   what to anchor the panel to
   * @param {Function} cb     receives the chosen value ('' means "no icon")
   * @param {Object}   [opts] {icons: true} to offer the bundled vector set
   */
  function open(near, cb, opts) {
    close();
    anchor = near;
    onPick = cb;

    panel = build(!!(opts && opts.icons));
    document.body.appendChild(panel);
    place(panel, near);
    panel.querySelector('.sym-search').focus();
  }

  document.addEventListener('click', function (ev) {
    if (!panel) return;
    if (panel.contains(ev.target)) return;

    /* The click that OPENED the panel also bubbles up to here, so without this
       the panel opens and closes again in the same event — which looked exactly
       like the button doing nothing. Checking the anchor covers any opener,
       rather than requiring each one to carry a marker attribute. */
    if (anchor && (anchor === ev.target || anchor.contains(ev.target))) return;

    close();
  });
  window.addEventListener('resize', close);

  /* Attach a picker button to any [data-symbols] field. Inserting at the caret
     rather than appending, and firing an `input` event afterwards so the
     editor's unsaved-changes tracking and live preview both notice.

     No icons tab here: the target is a text field, and a vector icon is a name
     rather than a character — inserting one would literally type "lucide:rocket"
     into the title. */
  function attach(field) {
    if (field.dataset.symbolsReady) return;
    field.dataset.symbolsReady = '1';

    var btn = el('button', 'icon-btn sym-toggle', '☺');
    btn.type = 'button';
    btn.setAttribute('data-symbols-btn', '');
    btn.setAttribute('data-tip', str('symbolsTip', 'Insert a symbol or emoji'));
    btn.setAttribute('aria-label', str('symbolsTip', 'Insert a symbol or emoji'));

    btn.addEventListener('click', function () {
      if (panel) { close(); return; }
      var start = field.selectionStart;
      var end = field.selectionEnd;

      open(btn, function (ch) {
        if (!ch) return;
        field.focus();
        try { field.setSelectionRange(start, end); } catch (e) { /* not a text field */ }
        if (field.setRangeText) {
          field.setRangeText(ch, start, end, 'end');
        } else {
          field.value += ch;
        }
        field.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });

    var wrap = el('span', 'sym-field');
    field.parentNode.insertBefore(wrap, field);
    wrap.appendChild(field);
    wrap.appendChild(btn);

    if (window.Tooltip) window.Tooltip.refresh(wrap);
  }

  function scan(root) {
    (root || document).querySelectorAll('[data-symbols]').forEach(attach);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }

  window.Symbols = {
    open: open, close: close, attach: attach, scan: scan, preview: preview, warm: warm
  };

  /**
   * Prefetch on intent.
   *
   * Anything marked [data-symbols-btn] or .icon-picker starts the fetches when a
   * pointer enters it or it takes focus. The travel time from noticing a button
   * to clicking it is usually longer than the fetch, so the data is there by the
   * time the panel opens — without every reader downloading 280 KB they may
   * never use.
   */
  ['pointerenter', 'focusin'].forEach(function (evt) {
    document.addEventListener(evt, function (ev) {
      var t = ev.target;
      if (t && t.closest && t.closest('[data-symbols-btn], .icon-picker')) warm();
    }, true);
  });
})();
