/* organice — dialogs.
 *
 * Replaces window.alert / confirm / prompt. Those are browser chrome: they
 * cannot be styled, they announce the origin ("127.0.0.1 says"), they look
 * wrong on every platform, and on mobile they are genuinely disorienting.
 *
 * Built on the native <dialog> element rather than a div-plus-overlay, which
 * gets several things right for free and correctly:
 *
 *   - focus is trapped inside while open, and the page behind is inert
 *   - Escape closes it (fires `cancel`, which resolves as a dismissal)
 *   - it renders in the browser's top layer, so no z-index arms race
 *   - ::backdrop is a real element, so the dim layer needs no extra markup
 *
 * The API is promise-based, so every call site has to become async. That is
 * the actual cost of this change and the reason the call sites read a little
 * differently now:
 *
 *   Dialog.alert('Message')                  -> Promise<void>
 *   Dialog.confirm('Sure?')                  -> Promise<boolean>
 *   Dialog.prompt('Label', {value: 'x'})     -> Promise<string|null>   null = cancelled
 */
(function () {
  'use strict';

  var T = window.T || {};

  var strings = {
    ok:      T.dlgOk      || 'OK',
    cancel:  T.dlgCancel  || 'Cancel',
    confirm: T.dlgConfirm || 'Confirm',
    close:   T.dlgClose   || 'Close',
    notice:  T.dlgNotice  || 'Notice'
  };

  /** The element that had focus before opening, so it can be given it back. */
  var lastFocus = null;

  function el(tag, className, text) {
    var n = document.createElement(tag);
    if (className) n.className = className;
    if (text != null) n.textContent = text;   // textContent, never innerHTML
    return n;
  }

  /**
   * @param {object} o
   *   kind        'alert' | 'confirm' | 'prompt'
   *   title       optional heading
   *   message     body text
   *   danger      style the primary action as destructive
   *   value       prompt: initial value
   *   placeholder prompt: input placeholder
   *   okLabel     override the primary button label
   */
  function open(o) {
    return new Promise(function (resolve) {
      /* Clear any straggler first. Only one dialog should ever exist; if an
         earlier one failed to tear down, leaving it in the DOM would capture
         `lastFocus` as ITS input and send focus somewhere meaningless when
         this one closes. */
      document.querySelectorAll('dialog.dlg').forEach(function (old) {
        if (old.open) old.close();
        old.remove();
      });

      lastFocus = document.activeElement;

      var dlg = el('dialog', 'dlg');
      var form = el('form', 'dlg-form');
      form.method = 'dialog';

      if (o.title) form.appendChild(el('h2', 'dlg-title', o.title));

      if (o.message) {
        var body = el('p', 'dlg-message', o.message);
        form.appendChild(body);
        /* The message is the accessible name of the dialog. Without this a
           screen reader announces the buttons and nothing else. */
        body.id = 'dlg-msg-' + Math.random().toString(36).slice(2, 9);
        dlg.setAttribute('aria-describedby', body.id);
      }

      var input = null;
      if (o.kind === 'prompt') {
        var label = el('label', 'dlg-label', o.label || '');
        var id = 'dlg-in-' + Math.random().toString(36).slice(2, 9);
        label.htmlFor = id;

        input = el('input', 'dlg-input');
        input.type = 'text';
        input.id = id;
        input.value = o.value || '';
        if (o.placeholder) input.placeholder = o.placeholder;
        input.autocomplete = 'off';

        if (o.label) form.appendChild(label);
        form.appendChild(input);
      }

      var actions = el('div', 'dlg-actions');

      if (o.kind !== 'alert') {
        var cancel = el('button', 'btn btn-ghost', o.cancelLabel || strings.cancel);
        cancel.type = 'button';
        cancel.value = 'cancel';
        cancel.addEventListener('click', function () { finish(null); });
        actions.appendChild(cancel);
      }

      var ok = el('button', 'btn' + (o.danger ? ' btn-danger' : ''),
                  o.okLabel || (o.kind === 'alert' ? strings.ok : strings.confirm));
      ok.type = 'submit';
      actions.appendChild(ok);

      form.appendChild(actions);
      dlg.appendChild(form);
      document.body.appendChild(dlg);

      var settled = false;

      function finish(result) {
        if (settled) return;
        settled = true;

        /* Wait for the closing transition before removing the node, or the
           dialog vanishes mid-animation. Falls through immediately when the
           reader has asked for reduced motion. */
        dlg.classList.add('closing');
        var done = function () {
          if (dlg.open) dlg.close();
          dlg.remove();
          if (lastFocus && lastFocus.focus) lastFocus.focus();
          resolve(result);
        };

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          done();
        } else {
          setTimeout(done, 120);
        }
      }

      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (o.kind === 'prompt') {
          var v = input.value.trim();
          // an empty prompt is a cancellation, not an empty answer
          finish(v === '' ? null : v);
        } else {
          finish(true);
        }
      });

      // Escape, or the backdrop close gesture
      dlg.addEventListener('cancel', function (ev) {
        ev.preventDefault();
        finish(o.kind === 'alert' ? undefined : null);
      });

      /* Clicking the backdrop dismisses. The event target IS the dialog when
         the click lands outside the form, because the form fills the visible
         card and the dialog element itself spans the whole top layer. */
      dlg.addEventListener('click', function (ev) {
        if (ev.target === dlg) finish(o.kind === 'alert' ? undefined : null);
      });

      /* No class toggling to become visible: the entrance is a CSS animation
         over an already-visible default state, so the dialog cannot end up
         invisible if the animation never runs. See .dlg in app.css. */
      dlg.showModal();

      if (input) {
        input.focus();
        input.select();
      } else {
        ok.focus();
      }
    });
  }

  window.Dialog = {
    alert: function (message, opts) {
      opts = opts || {};
      return open({
        kind: 'alert',
        title: opts.title || strings.notice,
        message: message,
        okLabel: opts.okLabel || strings.ok,
        danger: opts.danger
      });
    },

    confirm: function (message, opts) {
      opts = opts || {};
      return open({
        kind: 'confirm',
        title: opts.title,
        message: message,
        okLabel: opts.okLabel,
        cancelLabel: opts.cancelLabel,
        danger: opts.danger
      }).then(function (r) { return r === true; });
    },

    prompt: function (label, opts) {
      opts = opts || {};
      return open({
        kind: 'prompt',
        title: opts.title,
        label: label,
        message: opts.message,
        value: opts.value,
        placeholder: opts.placeholder,
        okLabel: opts.okLabel || strings.ok
      });
    }
  };
})();
