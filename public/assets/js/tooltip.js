/* organice — tooltips.
 *
 * Replaces the browser's native `title=` bubble, which is unstyleable, waits
 * about a second before appearing, vanishes after a few more, never appears on
 * keyboard focus, and does nothing at all on touch.
 *
 * Usage: put `data-tip="Some text"` on any element. Anything that still has a
 * plain `title` keeps the native behaviour, so this is opt-in.
 *
 *   <button data-tip="Bold (Ctrl+B)">B</button>
 *
 * ACCESSIBILITY. The tooltip is `aria-hidden` and the trigger gets a real
 * `aria-label` instead. A tooltip that is itself the accessible name has to be
 * wired with aria-describedby and stay in the DOM; naming the control directly
 * is simpler and works whether or not the tooltip ever shows. If the element
 * already has an aria-label or visible text, its label is left alone.
 *
 * TOUCH. Suppressed entirely on devices without a fine pointer. There is no
 * hover on a touchscreen, so a "tooltip" there is just a box that appears when
 * you press a button and covers what you were aiming at.
 */
(function () {
  'use strict';

  var hasHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  var tip = null;
  var timer = null;
  var current = null;

  function ensure() {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.className = 'tip';
    tip.setAttribute('role', 'tooltip');
    tip.setAttribute('aria-hidden', 'true');
    document.body.appendChild(tip);
    return tip;
  }

  function show(el) {
    var text = el.getAttribute('data-tip');
    if (!text) return;

    current = el;
    var t = ensure();
    t.textContent = text;
    t.classList.add('visible');

    var r = el.getBoundingClientRect();
    var tr = t.getBoundingClientRect();
    var gap = 8;

    /* Prefer below, flip above when there is no room — a tooltip clipped by the
       viewport is worse than one on the other side. */
    var top = r.bottom + gap;
    var below = true;
    if (top + tr.height > window.innerHeight - 4) {
      top = r.top - tr.height - gap;
      below = false;
    }

    // centre on the trigger, then pull back inside the viewport
    var left = r.left + (r.width / 2) - (tr.width / 2);
    left = Math.max(8, Math.min(left, window.innerWidth - tr.width - 8));

    /* Positioning through the CSSOM (el.style.top = …) rather than a style
       attribute in markup. The CSP here has no 'unsafe-inline', which blocks
       style="" ATTRIBUTES — but not CSSOM property setters, which is the one
       way to place an element at runtime without loosening the policy. */
    t.classList.toggle('above', !below);
    t.style.top = Math.round(top + window.scrollY) + 'px';
    t.style.left = Math.round(left + window.scrollX) + 'px';
  }

  function hide() {
    current = null;
    clearTimeout(timer);
    if (tip) tip.classList.remove('visible');
  }

  if (hasHover) {
    document.addEventListener('mouseover', function (ev) {
      var el = ev.target.closest('[data-tip]');
      if (!el || el === current) return;
      clearTimeout(timer);
      // short delay so sweeping the pointer across a toolbar does not flash
      timer = setTimeout(function () { show(el); }, 180);
    });

    document.addEventListener('mouseout', function (ev) {
      var el = ev.target.closest('[data-tip]');
      if (el) hide();
    });
  }

  /* Keyboard focus shows it immediately and on every device — someone tabbing
     through a toolbar of icon buttons has no other way to know what they do. */
  document.addEventListener('focusin', function (ev) {
    var el = ev.target.closest('[data-tip]');
    if (el) show(el);
  });
  document.addEventListener('focusout', hide);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') hide();
  });
  window.addEventListener('scroll', hide, { passive: true });
  window.addEventListener('resize', hide);

  /**
   * Give a tooltipped control a real accessible name when its visible text is
   * not one.
   *
   * "No text" is not the only failing case, and it turned out not to be the
   * common one here. A formatting toolbar is full of buttons whose entire
   * label is `B`, `▦`, `”` or `{ }` — those either produce no accessible name
   * at all (the letter sits inside a nested <strong>) or produce a useless one
   * that a screen reader reads out as punctuation. The tooltip text is the
   * better name in every one of those cases.
   *
   * Buttons with genuinely descriptive text ("Save", "Insert image") are left
   * alone: replacing their name could break the match between what is seen and
   * what is announced, which is its own accessibility failure.
   */
  function label(root) {
    (root || document).querySelectorAll('[data-tip]').forEach(function (el) {
      if (el.getAttribute('aria-label')) return;

      var text = el.textContent.trim();
      var descriptive = text.length > 3 && /[\p{L}\p{N}]{3}/u.test(text);
      if (descriptive) return;

      el.setAttribute('aria-label', el.getAttribute('data-tip'));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { label(); });
  } else {
    label();
  }

  window.Tooltip = { refresh: label, hide: hide };
})();
