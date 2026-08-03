# The editor

Markdown, with a live preview beside it. There is no rich text mode — see
"Rich text, and why it is gone" below.

The textarea holds the document and is the single source of truth. Everything
else — save, autosave, preview, translation, history — reads it.

## Panes

On a wide screen the source and the preview sit side by side, with a draggable
splitter between them. On a narrow screen they stack and only one shows; the
**Markdown / Preview** buttons choose which. On a wide screen those buttons only
move focus, since both panes are already visible.

The preview is rendered by `/api/preview` — the **same parser the published page
uses**, so it cannot show something a reader would not see.

Tab groups in the preview keep the tab you had open. The preview replaces its
whole HTML on each render, which resets every tab set to its first tab —
unusable if the thing you are editing is the *second* tab. `renderPreview()`
therefore captures the open tab per group and reapplies it afterwards, through
`window.Tabs` in `app.js`.

Selection is remembered **by label, not by index**: insert a tab before the one
you are on and index 2 is a different tab than it was a keystroke ago. It also
restores with `showTab()` rather than `.click()`, because a click writes the
reader's saved tab preference to `localStorage` and syncs every other group on
the page — a preview re-render must not do either.

## Toolbar

Wraps or prefixes the selection: bold, italic, inline code, H2, link, bullet and
numbered lists, quote, fenced code, callout, tabs, table, and image upload.
Every control carries a `data-tip` tooltip and an `aria-label`, since they are
icons with no visible text.

These insert Markdown at the caret. They do not insert a blank line first, so a
block inserted directly under a line of prose sits flush against it — which is
fine, because the parser lets tables, lists, headings, quotes, fences and `:::`
containers all interrupt a paragraph.

## Undo and redo

**Ctrl+Z** undoes, **Ctrl+Y** and **Ctrl+Shift+Z** redo. Both redo keys are
bound because Ctrl+Y is redo in Chrome but not in Firefox.

The editor keeps its **own** history rather than relying on the browser's.
Several operations legitimately assign `content.value` outright — swapping an
upload placeholder for the real link, restoring a revision, inserting a machine
translation — and every such assignment silently discards the browser's undo
stack, taking everything the author typed before it. Ctrl+Z then does nothing,
with no clue which edit killed it.

So `editor.js` keeps a stack of snapshots (value + selection, capped at 300) and
handles both keys itself:

- Typing is **coalesced** — a snapshot per keystroke would mean hundreds of
  presses to undo a paragraph. A 400 ms pause ends a step.
- Toolbar edits, uploads, translations and revision loads each commit one step,
  so they are undoable rather than unwinding to a stale document.
- A fresh edit after undoing discards the redo tail.

Toolbar edits still go through `execCommand('insertText')`, which is the only
textarea mutation a browser records as a user edit. That is now belt and braces
rather than the mechanism: the explicit stack is what Ctrl+Z actually reads.

## Rich text, and why it is gone

A WYSIWYG mode was built here and then removed at the maintainer's request.
Recording it so the same ground is not covered twice.

**What it was:** a `contenteditable` surface with a formatting toolbar. Entering
it rendered the Markdown through the server's own renderer; leaving it
serialised the DOM back to Markdown. `:::` containers and `@embed` lines were
lifted out before rendering and restored verbatim afterwards, appearing as
locked chips, so they survived a round trip byte-for-byte.

**It worked.** A document with headings, emphasis, inline code, links, images,
nested lists, a blockquote, a fenced code block, a table, a divider, a callout
and an embed round-tripped byte-identical, and all thirteen toolbar actions
serialised correctly.

**Why it went anyway:** for a documentation site whose storage format *is*
Markdown, a second way to edit the same document is a second thing to keep
correct. Every construct added to the Markdown parser afterwards would need a
matching serialiser rule, and the failure mode when they drift is silent content
loss on save — the worst kind of bug this app could have. The Markdown editor
with a live preview already shows you the rendered result as you type, which is
most of what rich text was for.

**If it is ever revisited**, the contract still holds: `editor.js` treats the
textarea's value as the only source of truth, so a mode needs nothing more than
a `getValue` / `setValue` pair over that string. The two rules that made the old
one safe are worth keeping:

1. Render Markdown → HTML with the **server's** renderer, never a second
   implementation in JavaScript. Two renderers drift, and the day they disagree
   the author sees one thing and the reader another.
2. Never convert what cannot be serialised back. Lift those blocks out and
   restore them verbatim.

Three smaller lessons from building it, all still true of this codebase:

- The renderer appends a `#` permalink to every heading. Any HTML→Markdown pass
  must skip `a.anchor`, or headings grow an extra link on each round trip.
- `execCommand` really does produce a list nested inside a `<p>`.
- `hidden` loses to any author `display:` rule — hence the
  `[hidden] { display: none !important }` guard in `app.css`.
