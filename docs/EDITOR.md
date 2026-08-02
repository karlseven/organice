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

## Toolbar

Wraps or prefixes the selection: bold, italic, inline code, H2, link, bullet and
numbered lists, quote, fenced code, callout, tabs, table, and image upload.

These insert Markdown at the caret. They do not insert a blank line first, so a
block inserted directly under a line of prose sits flush against it — which is
fine, because the parser lets tables, lists, headings, quotes, fences and `:::`
containers all interrupt a paragraph.

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
