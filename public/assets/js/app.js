/* organice — reader-side behaviour.
   No framework and no build step: everything here is progressive enhancement
   over a page that already works without it. */
(function () {
  'use strict';

  var base = window.APP_BASE || '';
  var url = function (p) { return base + p; };

  /** A translated string with an English fallback, so a missing key is still readable. */
  function str(key, fallback) {
    return (window.T && window.T[key]) || fallback;
  }

  // -------------------------------------------------------------------------
  // theme
  // -------------------------------------------------------------------------
  var root = document.documentElement;
  var stored = null;
  try { stored = localStorage.getItem('organice-theme'); } catch (e) { /* private mode */ }
  if (stored === 'dark' || stored === 'light') root.setAttribute('data-theme', stored);

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-theme-toggle]');
    if (!btn) return;
    /* From "auto" the first press goes to whichever theme the reader is NOT
       looking at — pressing a theme button and seeing nothing change is the
       one outcome that reads as broken. */
    var now = root.getAttribute('data-theme');
    var dark = now === 'dark' ||
      (now === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var next = dark ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('organice-theme', next); } catch (e) {}
  });

  // -------------------------------------------------------------------------
  // sidebar: mobile drawer + collapsible sections
  // -------------------------------------------------------------------------
  document.addEventListener('click', function (ev) {
    var toggle = ev.target.closest('[data-nav-toggle]');
    if (toggle) {
      var sb = document.querySelector('[data-sidebar]');
      if (!sb) return;
      var open = sb.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }

    var caret = ev.target.closest('[data-nav-caret]');
    if (caret) {
      var item = caret.closest('.nav-item');
      var isOpen = item.classList.toggle('open');
      caret.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
  });

  // -------------------------------------------------------------------------
  // copy button on code blocks
  // -------------------------------------------------------------------------
  /* The button ships empty from the server, because its HTML is cached per
     revision and any wording stored there would be stuck in the language the
     page was saved in. Labelling it here means it always matches the language
     being read. */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.textContent = str('copy', 'Copy');
    b.setAttribute('data-tip', str('copyTip', 'Copy to clipboard'));
  });
  if (window.Tooltip) window.Tooltip.refresh();

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    var code = btn.parentElement.querySelector('pre code');
    if (!code) return;

    navigator.clipboard.writeText(code.innerText).then(function () {
      var was = btn.textContent;
      btn.textContent = str('copied', 'Copied');
      setTimeout(function () { btn.textContent = was; }, 1400);
    }).catch(function () {
      btn.textContent = str('copyManual', 'Press Ctrl+C');
    });
  });

  // -------------------------------------------------------------------------
  // search-as-you-type
  // -------------------------------------------------------------------------
  var box = document.querySelector('[data-search]');
  if (box) {
    var input = box.querySelector('input[name="q"]');
    var panel = box.querySelector('[data-search-results]');
    var timer = null;
    var seq = 0;

    var close = function () { panel.hidden = true; panel.innerHTML = ''; };

    var run = function (q) {
      /* Each request carries a sequence number and a slower earlier response is
         discarded. Without it, typing "ins" then "install" can render the "ins"
         results last, and the box appears to lag a keystroke behind forever. */
      var mine = ++seq;
      fetch(url('/api/search?q=') + encodeURIComponent(q), {
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.ok ? r.json() : { results: [] }; })
        .then(function (data) {
          if (mine !== seq) return;
          if (!data.results.length) {
            panel.innerHTML = '<p class="r-none">' + esc(str('noMatches', 'No matches.')) + '</p>';
            panel.hidden = false;
            return;
          }
          panel.innerHTML = data.results.map(function (r) {
            return '<a href="' + esc(r.url) + '">' +
              '<span class="r-space">' + esc(r.space) + '</span>' +
              '<span class="r-title">' + esc(r.title) + '</span>' +
              '<span class="r-ex">' + esc(r.excerpt) + '</span></a>';
          }).join('');
          panel.hidden = false;
        })
        .catch(close);
    };

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(function () { run(q); }, 160);
    });

    input.addEventListener('keydown', function (ev) {
      var items = Array.prototype.slice.call(panel.querySelectorAll('a'));
      var at = items.findIndex(function (a) { return a.classList.contains('sel'); });

      if (ev.key === 'Escape') { close(); input.blur(); return; }
      if (ev.key === 'Enter' && at >= 0) { ev.preventDefault(); items[at].click(); return; }
      if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;

      ev.preventDefault();
      if (!items.length) return;
      if (at >= 0) items[at].classList.remove('sel');
      var next = ev.key === 'ArrowDown'
        ? (at + 1) % items.length
        : (at <= 0 ? items.length - 1 : at - 1);
      items[next].classList.add('sel');
      items[next].scrollIntoView({ block: 'nearest' });
    });

    document.addEventListener('click', function (ev) {
      if (!box.contains(ev.target)) close();
    });

    // "/" focuses search, unless the reader is already typing somewhere
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== '/' || ev.metaKey || ev.ctrlKey) return;
      var t = ev.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
      ev.preventDefault();
      input.focus();
      input.select();
    });
  }

  // -------------------------------------------------------------------------
  // table of contents: highlight the section being read
  // -------------------------------------------------------------------------
  var tocLinks = document.querySelectorAll('[data-toc-link]');
  if (tocLinks.length && 'IntersectionObserver' in window) {
    var map = {};
    tocLinks.forEach(function (a) { map[a.getAttribute('href').slice(1)] = a; });

    var seen = new Set();
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) seen.add(en.target.id); else seen.delete(en.target.id);
      });
      /* Highlight the FIRST heading currently on screen rather than the last
         one crossed: with several short sections visible at once, the last-
         crossed rule makes the marker jump ahead of what is being read. */
      var active = null;
      Object.keys(map).some(function (id) {
        if (seen.has(id)) { active = id; return true; }
        return false;
      });
      tocLinks.forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('href') === '#' + active);
      });
    }, { rootMargin: '-70px 0px -70% 0px' });

    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) io.observe(el);
    });
  }

  // -------------------------------------------------------------------------
  // create a page (sidebar + empty space button)
  // -------------------------------------------------------------------------
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-new-page]');
    if (!btn) return;

    /* Present on the "+" in each sidebar row, absent on the space-level button
       at the foot. 0 means top level, which is what the API expects. */
    var parent = Number(btn.dataset.parent || 0);

    Dialog.prompt(str('newPageLabel', 'Page title'), {
      title: parent
        ? str('newSubpage', 'Title for the new subpage')
        : str('newPage', 'Title for the new page'),
      placeholder: str('newPagePlaceholder', 'Getting started')
    }).then(function (title) {
      if (!title) return;

      btn.disabled = true;
      return fetch(url('/api/pages'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({ space_id: Number(btn.dataset.space), title: title, parent_id: parent })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.d.message || 'Could not create the page');
          window.location.href = res.d.edit_url;
        })
        .catch(function (err) {
          btn.disabled = false;
          Dialog.alert(err.message, { title: str('failed', 'That did not work') });
        });
    });
  });

  // -------------------------------------------------------------------------
  // video embeds: insert the third-party frame only on demand
  // -------------------------------------------------------------------------
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.embed-play');
    if (!btn) return;

    var fig = btn.closest('[data-embed]');
    var src = fig && fig.dataset.embed;
    if (!src) return;

    var frame = document.createElement('iframe');
    frame.src = src;
    frame.title = btn.getAttribute('aria-label') || 'Video';
    frame.loading = 'lazy';
    frame.allow = 'autoplay; fullscreen; picture-in-picture';
    frame.allowFullscreen = true;
    frame.referrerPolicy = 'no-referrer';
    /* Sandboxed: the player needs scripts and same-origin for its own storage,
       but nothing here should let it navigate the page it sits in. */
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');

    btn.replaceWith(frame);
  });

  // -------------------------------------------------------------------------
  // language menu
  // -------------------------------------------------------------------------
  /* The menu is plain links inside a <ul> that starts hidden, so the whole
     thing still works with scripting off — this only adds the open/close. */
  document.addEventListener('click', function (ev) {
    var toggle = ev.target.closest('[data-lang-toggle]');
    var menu = document.querySelector('[data-lang-menu]');
    if (!menu) return;
    var list = menu.querySelector('[data-lang-list]');

    if (toggle) {
      list.hidden = !list.hidden;
      toggle.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
      return;
    }
    if (!menu.contains(ev.target)) {
      list.hidden = true;
      menu.querySelector('[data-lang-toggle]').setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var list = document.querySelector('[data-lang-list]');
    if (list) list.hidden = true;
  });

  // -------------------------------------------------------------------------
  // tabs / code groups
  // -------------------------------------------------------------------------
  /* Show one tab of one group. Split out from the click handler so it can be
     replayed against freshly inserted markup — the editor's live preview
     rebuilds its HTML on every keystroke and has to put the selection back. */
  function showTab(group, idx) {
    group.querySelectorAll('.tab-btn').forEach(function (b) {
      var on = b.dataset.tab === idx;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    group.querySelectorAll('.tab-panel').forEach(function (p) {
      p.hidden = p.dataset.tab !== idx;
    });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.tab-btn');
    if (!btn) return;

    var group = btn.closest('.tabs');
    showTab(group, btn.dataset.tab);

    /* Remembered by LABEL, not by index, and applied to every tab set on the
       page. Someone reading install docs on Windows wants every "macOS /
       Windows / Linux" group to follow them, not just the one they clicked. */
    try { localStorage.setItem('organice-tab', btn.textContent.trim()); } catch (e) {}
    syncTabs(btn.textContent.trim(), group);
  });

  function syncTabs(label, except) {
    document.querySelectorAll('.tabs').forEach(function (group) {
      if (group === except) return;
      var match = Array.prototype.slice.call(group.querySelectorAll('.tab-btn'))
        .find(function (b) { return b.textContent.trim() === label; });
      if (match) match.click();
    });
  }

  try {
    var prefTab = localStorage.getItem('organice-tab');
    if (prefTab) syncTabs(prefTab, null);
  } catch (e) {}

  /*
   * Read and reapply which tab is open in each group under `root`.
   *
   * Selection is captured by LABEL rather than by tab index, because an edit
   * may have inserted or removed a tab: after adding "yarn" before "pnpm",
   * index 2 is a different tab than it was a keystroke ago, and restoring by
   * index would silently switch what the author is looking at. A label that no
   * longer exists restores nothing, leaving that group on its first tab.
   */
  window.Tabs = {
    capture: function (root) {
      return Array.prototype.map.call(root.querySelectorAll('.tabs'), function (g) {
        var on = g.querySelector('.tab-btn.active');
        return on ? on.textContent.trim() : null;
      });
    },
    restore: function (root, state) {
      root.querySelectorAll('.tabs').forEach(function (g, i) {
        if (!state[i]) return;
        var match = Array.prototype.slice.call(g.querySelectorAll('.tab-btn'))
          .find(function (b) { return b.textContent.trim() === state[i]; });
        /* showTab, not click(): a click would write localStorage and sync every
           other group on the page, so re-rendering a preview would rewrite the
           reader's saved preference as a side effect. */
        if (match) showTab(g, match.dataset.tab);
      });
    }
  };

  // -------------------------------------------------------------------------
  // drag-and-drop page reordering (editors only — the sidebar marks itself)
  // -------------------------------------------------------------------------
  var tree = document.querySelector('[data-sortable]');
  if (tree) {
    var dragged = null;

    tree.addEventListener('dragstart', function (ev) {
      var row = ev.target.closest('.nav-row');
      if (!row) return;
      dragged = row.closest('.nav-item');
      ev.dataTransfer.effectAllowed = 'move';
      // Firefox will not start a drag without data on the transfer
      ev.dataTransfer.setData('text/plain', dragged.dataset.pageId);
      dragged.classList.add('dragging');
    });

    tree.addEventListener('dragend', function () {
      tree.querySelectorAll('.drop-before, .drop-after, .drop-into, .dragging')
        .forEach(function (el) { el.classList.remove('drop-before', 'drop-after', 'drop-into', 'dragging'); });
      dragged = null;
    });

    tree.addEventListener('dragover', function (ev) {
      var row = ev.target.closest('.nav-row');
      if (!row || !dragged) return;
      var item = row.closest('.nav-item');
      if (item === dragged || dragged.contains(item)) return;   // no dropping into yourself

      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';

      /* Three zones down the row: the top and bottom quarters reorder among
         siblings, the middle half nests. Without a distinct nest zone there is
         no way to express "make this a child" by dragging at all. */
      var box = row.getBoundingClientRect();
      var rel = (ev.clientY - box.top) / box.height;
      var mode = rel < 0.25 ? 'before' : (rel > 0.75 ? 'after' : 'into');

      row.classList.remove('drop-before', 'drop-after', 'drop-into');
      row.classList.add('drop-' + mode);
      row.dataset.dropMode = mode;
    });

    tree.addEventListener('dragleave', function (ev) {
      var row = ev.target.closest('.nav-row');
      if (row) row.classList.remove('drop-before', 'drop-after', 'drop-into');
    });

    tree.addEventListener('drop', function (ev) {
      var row = ev.target.closest('.nav-row');
      if (!row || !dragged) return;
      ev.preventDefault();

      var target = row.closest('.nav-item');
      var zone = row.dataset.dropMode || 'after';
      var body;

      if (zone === 'into') {
        body = { parent_id: Number(target.dataset.pageId), mode: 'last' };
      } else if (zone === 'after') {
        body = { parent_id: Number(target.dataset.parentId || 0), mode: 'after',
                 after_id: Number(target.dataset.pageId) };
      } else {
        /* "Before X" is expressed as "after whatever precedes X" — the server
           only understands first/last/after, because those are the three
           statements that stay unambiguous when siblings share a position. */
        var prev = target.previousElementSibling;
        while (prev && !prev.classList.contains('nav-item')) prev = prev.previousElementSibling;
        body = prev
          ? { parent_id: Number(target.dataset.parentId || 0), mode: 'after',
              after_id: Number(prev.dataset.pageId) }
          : { parent_id: Number(target.dataset.parentId || 0), mode: 'first' };
      }

      fetch(url('/api/pages/' + dragged.dataset.pageId + '/move'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify(body)
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.d.message || 'Could not move the page');
          /* Reloaded rather than re-sorted in place: a move can change the
             page's own URL and every descendant's, and re-deriving all of that
             client-side is a second implementation of the server's rules that
             would drift from it. */
          window.location.reload();
        })
        .catch(function (err) {
          Dialog.alert(err.message, { title: str('failed', 'That did not work') });
        });
    });
  }

  // -------------------------------------------------------------------------
  // destructive forms ask first
  // -------------------------------------------------------------------------
  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('[data-confirm]');
    if (!form || form.dataset.confirmed === 'yes') return;

    /* The confirmation is asynchronous now, so the submit must ALWAYS be
       cancelled and replayed on approval. Marking the form is what stops the
       replayed submit from asking again forever. */
    ev.preventDefault();

    Dialog.confirm(form.dataset.confirm, {
      title: str('confirmTitle', 'Are you sure?'),
      okLabel: form.dataset.confirmOk || str('deleteLabel', 'Delete'),
      danger: true
    }).then(function (ok) {
      if (!ok) return;
      form.dataset.confirmed = 'yes';
      /* requestSubmit, not submit(): submit() skips validation and bypasses
         this listener in ways that differ between browsers. */
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
  });

  // -------------------------------------------------------------------------
  function csrf() {
    var el = document.querySelector('input[name="_token"]');
    return el ? el.value : '';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
