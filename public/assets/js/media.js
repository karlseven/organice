/* organice — the media library.
 *
 * Drives the markup in Views/partials/media-library.php, which ships empty.
 * One implementation serves both places it appears: the standalone /media page
 * and the picker modal the editor opens. The only difference is `data-picker`,
 * which turns on the Insert button and single-click selection.
 *
 * Folders are virtual — a label on each row. Nothing here moves a file; it
 * moves a string, which is why reorganising the library can never break a page
 * that already points at an image.
 */
(function () {
  'use strict';

  var T = window.T || {};
  var str = function (k, fb) { return T[k] || fb; };
  var base = window.APP_BASE || '';
  var url = function (p) { return base + p; };

  function csrf() {
    var el = document.querySelector('input[name="_token"]');
    return el ? el.value : (window.ED ? window.ED.token : '');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function human(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  /*
   * Substitute :placeholders.
   *
   * LONGEST KEY FIRST, and that is not a nicety: ':n' is a prefix of ':name',
   * so replacing in declaration order turns "Delete ":name"?" into
   * "Delete "1ame"?" the moment a message carries both. Sorting by length
   * means the longer name always wins the overlap.
   */
  function fill(s, vars) {
    Object.keys(vars)
      .sort(function (a, b) { return b.length - a.length; })
      .forEach(function (k) { s = s.split(k).join(vars[k]); });
    return s;
  }

  /*
   * "1 items" is the kind of detail that makes software feel unfinished.
   *
   * Two strings per count rather than a plural-rules engine: the seven
   * languages here are English plus six that do not inflect nouns for number
   * at all, so in those catalogues both forms are simply the same sentence.
   * A CLDR plural library would be a lot of machinery to serve one language.
   */
  /* Returns the TEMPLATE, unsubstituted. Filling here would run a second,
     earlier pass that cannot see the caller's other variables — which is how
     ':n' got to eat the ':n' in ':name'. One fill(), all vars, once. */
  function plural(n, oneKey, manyKey, oneFb, manyFb) {
    return n === 1 ? str(oneKey, oneFb) : str(manyKey, manyFb);
  }

  function countLabel(n) {
    return fill(plural(n, 'mediaCountOne', 'mediaCount', ':n item', ':n items'), { ':n': n });
  }

  /**
   * Wire one library instance. Called for the page on load, and again for the
   * editor modal each time it is built.
   */
  function mount(root) {
    if (!root || root.__mounted) return;
    root.__mounted = true;

    var spaceId = Number(root.dataset.space);
    var picker  = root.dataset.picker === '1';

    var grid    = root.querySelector('[data-media-grid]');
    var crumbs  = root.querySelector('[data-media-crumbs]');
    var note    = root.querySelector('[data-media-note]');
    var countEl = root.querySelector('[data-media-count]');
    var search  = root.querySelector('[data-media-search]');
    var fileIn  = root.querySelector('[data-media-file]');
    var insertBtn = root.querySelector('[data-media-insert]');

    var folder = '';
    var query  = '';
    var items  = [];
    var folders = [];
    /* Folders someone just made. They have no rows yet, so the server cannot
       know about them — a folder exists only because assets carry its name.
       Keeping them here means you can create one and then drag into it, which
       is the order people actually work in. */
    var pending = [];
    var selected = null;
    var seq = 0;

    // -------------------------------------------------------------------------
    // load
    // -------------------------------------------------------------------------
    function load() {
      var mine = ++seq;
      grid.setAttribute('aria-busy', 'true');

      var qs = '?space=' + spaceId + '&folder=' + encodeURIComponent(folder)
             + '&q=' + encodeURIComponent(query);

      fetch(url('/api/media') + qs, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          // a slower earlier request must not overwrite a newer folder
          if (mine !== seq) return;
          if (!res.ok) throw new Error(res.d.message || 'Could not load the library');
          items   = res.d.items || [];
          folders = res.d.folders || [];
          draw();
        })
        .catch(function (err) {
          if (mine !== seq) return;
          grid.innerHTML = '';
          grid.setAttribute('aria-busy', 'false');
          say(err.message);
        });
    }

    function say(msg) {
      note.textContent = msg || '';
      note.hidden = !msg;
    }

    // -------------------------------------------------------------------------
    // draw
    // -------------------------------------------------------------------------
    function draw() {
      drawCrumbs();

      var shown = folders.slice();
      /* Pending folders only make sense in the folder you made them in, and
         only until the server knows about them. */
      pending.forEach(function (p) {
        if (p.parent !== folder) return;
        if (shown.some(function (f) { return f.path === p.path; })) return;
        shown.push({ path: p.path, name: p.name, count: 0, pending: true });
      });
      shown.sort(function (a, b) { return a.name.localeCompare(b.name); });

      var html = '';

      // searching flattens the tree — folder tiles would be noise
      if (!query) {
        shown.forEach(function (f) {
          html += '<button class="media-tile media-folder" type="button" data-folder="' + esc(f.path) + '">'
                +   '<span class="media-folder-ico">' + folderSvg() + '</span>'
                +   '<span class="media-tile-name">' + esc(f.name) + '</span>'
                +   '<span class="media-tile-meta">' + esc(f.pending
                        ? str('mediaEmpty', 'empty')
                        : countLabel(f.count)) + '</span>'
                + '</button>';
        });
      }

      items.forEach(function (it) {
        html += '<figure class="media-tile media-item" tabindex="0" draggable="true"'
              +   ' data-id="' + it.id + '" data-md="' + esc(it.markdown) + '">'
              +   '<span class="media-thumb">'
              +     (it.image
                      /* Lazy, because a folder can hold hundreds and they are
                         all offscreen until scrolled to. decoding=async keeps
                         a large PNG from blocking the grid's first paint. */
                      ? '<img src="' + esc(it.url) + '" alt="" loading="lazy" decoding="async">'
                      : '<span class="media-fileicon">' + esc(ext(it.name)) + '</span>')
              +   '</span>'
              +   '<figcaption class="media-tile-name" title="' + esc(it.name) + '">' + esc(it.name) + '</figcaption>'
              +   '<span class="media-tile-meta">' + esc(human(it.size)) + '</span>'
              +   '<span class="media-actions">'
              +     btn('rename', str('mediaRename', 'Rename'))
              +     btn('copy',   str('mediaCopy', 'Copy Markdown'))
              +     btn('delete', str('mediaDelete', 'Delete'))
              +   '</span>'
              + '</figure>';
      });

      grid.innerHTML = html;
      grid.setAttribute('aria-busy', 'false');

      if (!items.length && !shown.length) {
        say(query ? fill(str('mediaNoMatch', 'Nothing matches ":q".'), { ':q': query })
                  : str('mediaEmptyFolder', 'Nothing here yet. Upload something, or drag files in.'));
      } else {
        say('');
      }

      countEl.textContent = items.length ? countLabel(items.length) : '';

      selected = null;
      if (insertBtn) insertBtn.disabled = true;
    }

    function btn(action, label) {
      return '<button class="media-act" type="button" data-act="' + action + '"'
           + ' data-tip="' + esc(label) + '" aria-label="' + esc(label) + '">'
           + actSvg(action) + '</button>';
    }

    function ext(name) {
      var i = String(name).lastIndexOf('.');
      return i === -1 ? 'file' : String(name).slice(i + 1).toUpperCase().slice(0, 4);
    }

    function drawCrumbs() {
      var html = '<button class="crumb" type="button" data-folder="">'
               + esc(str('mediaRoot', 'All files')) + '</button>';
      var walked = '';
      if (folder) {
        folder.split('/').forEach(function (seg) {
          walked = walked ? walked + '/' + seg : seg;
          html += '<span class="crumb-sep">/</span>'
                + '<button class="crumb" type="button" data-folder="' + esc(walked) + '">'
                + esc(seg) + '</button>';
        });
        html += ' <button class="media-act crumb-rename" type="button" data-act="renamefolder"'
              + ' data-tip="' + esc(str('mediaRenameFolder', 'Rename this folder')) + '"'
              + ' aria-label="' + esc(str('mediaRenameFolder', 'Rename this folder')) + '">'
              + actSvg('rename') + '</button>';
      }
      crumbs.innerHTML = html;
    }

    // -------------------------------------------------------------------------
    // interaction
    // -------------------------------------------------------------------------
    root.addEventListener('click', function (ev) {
      var crumb = ev.target.closest('[data-folder]');
      var act   = ev.target.closest('[data-act]');

      if (act) {
        ev.stopPropagation();
        var tile = act.closest('.media-item');
        if (act.dataset.act === 'renamefolder') return renameFolder();
        if (tile) return itemAction(act.dataset.act, tile);
        return;
      }

      if (crumb) {
        query = '';
        if (search) search.value = '';
        folder = crumb.dataset.folder;
        return load();
      }

      var item = ev.target.closest('.media-item');
      if (!item) return;
      select(item);
      /* Double-click inserts. In the picker a single click only selects, so
         that Insert stays a deliberate act rather than something a stray click
         does to the document. */
      if (picker && ev.detail > 1) doInsert();
    });

    root.addEventListener('keydown', function (ev) {
      var item = ev.target.closest('.media-item');
      if (!item || (ev.key !== 'Enter' && ev.key !== ' ')) return;
      ev.preventDefault();
      select(item);
      if (picker) doInsert();
    });

    function select(el) {
      Array.prototype.forEach.call(root.querySelectorAll('.media-item.sel'),
        function (x) { x.classList.remove('sel'); });
      el.classList.add('sel');
      selected = el;
      if (insertBtn) insertBtn.disabled = false;
    }

    if (search) {
      var timer = null;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { query = search.value.trim(); load(); }, 200);
      });
    }

    // ---- drag an item onto a folder tile or a breadcrumb --------------------
    var dragged = null;

    root.addEventListener('dragstart', function (ev) {
      var item = ev.target.closest('.media-item');
      if (!item) return;
      dragged = item;
      ev.dataTransfer.effectAllowed = 'move';
      /* Firefox refuses to start a drag unless something is set. */
      ev.dataTransfer.setData('text/plain', item.dataset.md || '');
    });

    root.addEventListener('dragover', function (ev) {
      var target = dropTarget(ev);
      if (!target || !dragged) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      target.classList.add('drop-into');
    });

    root.addEventListener('dragleave', function (ev) {
      var target = dropTarget(ev);
      if (target) target.classList.remove('drop-into');
    });

    root.addEventListener('drop', function (ev) {
      var target = dropTarget(ev);
      if (!target || !dragged) return;
      ev.preventDefault();
      target.classList.remove('drop-into');
      moveItem(dragged, target.dataset.folder);
      dragged = null;
    });

    function dropTarget(ev) {
      /* A folder tile or a breadcrumb — both carry data-folder, and dropping
         on a crumb is how you move something UP a level. */
      var el = ev.target.closest('[data-folder]');
      return el && el !== dragged ? el : null;
    }

    function moveItem(tile, to) {
      fetch(url('/api/media/' + tile.dataset.id + '/move'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({ space_id: spaceId, folder: to })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.d.message || 'Could not move that file');
          /* Reloaded rather than removed from the DOM: the destination's count
             changed too, and recomputing it here would be a second copy of the
             server's arithmetic. */
          load();
        })
        .catch(function (err) { Dialog.alert(err.message, { title: str('failed', 'That did not work') }); });
    }

    // ---- per-item actions ---------------------------------------------------
    function itemAction(act, tile) {
      var id = tile.dataset.id;
      var it = items.filter(function (x) { return String(x.id) === String(id); })[0];
      if (!it) return;

      if (act === 'copy') return copy(it.markdown, tile);
      if (act === 'rename') return rename(it);
      if (act === 'delete') return remove(it);
    }

    function copy(text, tile) {
      var done = function () {
        tile.classList.add('copied');
        setTimeout(function () { tile.classList.remove('copied'); }, 1200);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {
          Dialog.alert(text, { title: str('mediaCopy', 'Copy Markdown') });
        });
      } else {
        Dialog.alert(text, { title: str('mediaCopy', 'Copy Markdown') });
      }
    }

    function rename(it) {
      Dialog.prompt(str('mediaNewName', 'File name'), {
        title: str('mediaRename', 'Rename'),
        value: it.name
      }).then(function (name) {
        if (!name || name === it.name) return;
        fetch(url('/api/media/' + it.id + '/rename'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
          body: JSON.stringify({ space_id: spaceId, name: name })
        })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            if (!res.ok) throw new Error(res.d.message || 'Could not rename that file');
            load();
          })
          .catch(function (err) { Dialog.alert(err.message, { title: str('failed', 'That did not work') }); });
      });
    }

    /**
     * Delete, but ask the server what would break first.
     *
     * A file in the library looks inert; the pages using it are somewhere else
     * entirely. Naming them in the confirmation is the difference between an
     * informed decision and finding three broken images next week.
     */
    function remove(it) {
      fetch(url('/api/media/' + it.id + '/usage?space=' + spaceId), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { pages: [] }; })
        .catch(function () { return { pages: [] }; })
        .then(function (d) {
          var pages = d.pages || [];
          var msg = pages.length
            ? fill(plural(pages.length, 'mediaDeleteUsedOne', 'mediaDeleteUsed',
                          'Delete ":name"? It is used on 1 page, which will show a broken image.',
                          'Delete ":name"? It is used on :n pages, which will show a broken image.'),
                   { ':name': it.name, ':n': pages.length })
            : fill(str('mediaDeleteAsk', 'Delete ":name"? No page uses it.'), { ':name': it.name });

          return Dialog.confirm(msg, {
            title: str('mediaDelete', 'Delete'),
            okLabel: str('deleteLabel', 'Delete'),
            danger: true
          });
        })
        .then(function (ok) {
          if (!ok) return;
          return fetch(url('/api/media/' + it.id + '/delete'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ space_id: spaceId })
          }).then(function (r) {
            if (!r.ok) throw new Error('Could not delete that file');
            load();
          });
        })
        .catch(function (err) { Dialog.alert(err.message, { title: str('failed', 'That did not work') }); });
    }

    // ---- folders ------------------------------------------------------------
    var newFolderBtn = root.querySelector('[data-media-newfolder]');
    if (newFolderBtn) newFolderBtn.addEventListener('click', function () {
      Dialog.prompt(str('mediaFolderName', 'Folder name'), {
        title: str('mediaNewFolder', 'New folder')
      }).then(function (name) {
        if (!name) return;
        name = name.replace(/[\/\\]+/g, '-').trim();
        if (!name) return;
        var path = folder ? folder + '/' + name : name;
        pending.push({ path: path, name: name, parent: folder });
        draw();
      });
    });

    function renameFolder() {
      var current = folder.split('/').pop();
      Dialog.prompt(str('mediaFolderName', 'Folder name'), {
        title: str('mediaRenameFolder', 'Rename this folder'),
        value: current
      }).then(function (name) {
        if (!name) return;
        name = name.replace(/[\/\\]+/g, '-').trim();
        if (!name || name === current) return;

        var parent = folder.indexOf('/') === -1 ? '' : folder.slice(0, folder.lastIndexOf('/'));
        var to = parent ? parent + '/' + name : name;

        fetch(url('/api/media/folder'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
          body: JSON.stringify({ space_id: spaceId, from: folder, to: to })
        })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            if (!res.ok) throw new Error(res.d.message || 'Could not rename that folder');
            folder = res.d.folder;
            load();
          })
          .catch(function (err) { Dialog.alert(err.message, { title: str('failed', 'That did not work') }); });
      });
    }

    // ---- upload straight into the folder being viewed -----------------------
    var upBtn = root.querySelector('[data-media-upload]');
    if (upBtn && fileIn) {
      upBtn.addEventListener('click', function () { fileIn.click(); });
      fileIn.addEventListener('change', function () {
        var files = Array.prototype.slice.call(fileIn.files || []);
        fileIn.value = '';
        if (files.length) uploadAll(files);
      });
    }

    /* Dropping files from the desktop onto the grid. Guarded on dataTransfer
       .files because an internal tile drag also fires these events. */
    grid.addEventListener('dragover', function (ev) {
      if (dragged) return;
      ev.preventDefault();
      grid.classList.add('drop-files');
    });
    grid.addEventListener('dragleave', function () { grid.classList.remove('drop-files'); });
    grid.addEventListener('drop', function (ev) {
      grid.classList.remove('drop-files');
      if (dragged) return;
      var files = Array.prototype.slice.call((ev.dataTransfer || {}).files || []);
      if (!files.length) return;
      ev.preventDefault();
      uploadAll(files);
    });

    function uploadAll(files) {
      say(fill(str('mediaUploading', 'Uploading :n…'), { ':n': files.length }));

      /* Sequential, not parallel. Ten images at once is ten PHP workers each
         holding a file in memory, and on a small VPS that is how an upload
         batch becomes a 502. */
      var i = 0;
      function next() {
        if (i >= files.length) { say(''); load(); return; }
        var body = new FormData();
        body.append('file', files[i++]);
        body.append('space_id', String(spaceId));
        body.append('_token', csrf());

        fetch(url('/api/upload'), { method: 'POST', headers: { 'X-CSRF-Token': csrf() }, body: body })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            if (!res.ok) throw new Error(res.d.message || 'Upload failed');
            /* Uploads land in the root, then get filed into the folder being
               viewed — /api/upload knows nothing about folders, and teaching
               it would mean two places that decide where a file goes. */
            if (folder && res.d.id) {
              return fetch(url('/api/media/' + res.d.id + '/move'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({ space_id: spaceId, folder: folder })
              });
            }
          })
          .then(next)
          .catch(function (err) {
            say('');
            Dialog.alert(err.message, { title: str('uploadFail', 'Upload failed') });
          });
      }
      next();
    }

    // ---- insert (picker only) ----------------------------------------------
    function doInsert() {
      if (!selected) return;
      var md = selected.dataset.md;
      if (typeof root.__onInsert === 'function') root.__onInsert(md);
    }
    if (insertBtn) insertBtn.addEventListener('click', doInsert);

    root.__reload = load;
    load();
  }

  // ---------------------------------------------------------------------------
  // icons, inline so the grid needs no sprite fetch
  // ---------------------------------------------------------------------------
  function svg(inner) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
         + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + inner + '</svg>';
  }
  function folderSvg() {
    return svg('<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>');
  }
  function actSvg(a) {
    if (a === 'rename') return svg('<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4Z"/>');
    if (a === 'copy')   return svg('<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>');
    return svg('<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>');
  }

  window.Media = { mount: mount };

  document.addEventListener('DOMContentLoaded', function () {
    var page = document.querySelector('[data-media]');
    if (page) mount(page);

    var form = document.querySelector('[data-media-spaceform]');
    if (form) {
      form.querySelector('select').addEventListener('change', function () { form.submit(); });
      /* The submit button only exists for the no-JavaScript case; with the
         change handler live it is one more thing to look at. */
      var go = form.querySelector('[data-media-spacego]');
      if (go) go.hidden = true;
    }
  });
})();
