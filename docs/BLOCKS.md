# Content blocks

Everything an author can write beyond plain Markdown. All of it is parsed in
`Core\Markdown` at save time and cached as HTML — **after changing the parser,
run `php scripts/rerender.php`** or existing pages keep their old markup.

## Callouts

```
:::info
For something a reader should know.
:::

:::warning Careful
For something that will bite them.
:::
```

Kinds: `info`, `tip`, `note`, `warning`, `danger`. The text after the kind is an
optional title.

## Tabs and code groups

```
:::tabs
=== macOS
brew install thing

=== Windows
winget install thing
:::
```

A code group is the same thing with one fenced block per tab — no separate
syntax, because they are the same thing to a reader.

Every panel is rendered into the HTML and hidden with an attribute rather than
fetched on demand, so in-page find, printing and search engines all see the
whole page.

The choice is remembered **by label** across the whole site: someone reading
install docs on Windows gets every "macOS / Windows / Linux" group following
them, not just the one they clicked.

## Collapsed sections

```
:::details Why is this collapsed?
Because it is an aside.
:::
```

Built on `<details>`/`<summary>`, not a div plus JavaScript — that gets keyboard
support and correct semantics for free.

## Cards

```
:::cards
=== Installing
How to get it running. [Read](/s/handbook/install)

=== Configuring
Every setting explained. [Read](/s/handbook/config)
:::
```

If a card's body contains **exactly one** link, the whole card becomes that
link. A card that looks clickable but only responds on four words of text is a
small, constant irritation. Two or more links, and it stays a plain card.

## Steps

```
:::steps
=== Install it
Run the installer.

=== Configure it
Edit the file.
:::
```

Numbers come from a CSS counter against a connecting rail, so inserting a step
in the middle does not mean renumbering by hand. The markup is an `<ol>`, so it
degrades to a numbered list with styles off.

## Media embeds

```
@embed https://www.youtube.com/watch?v=VIDEO_ID An optional caption
@embed https://vimeo.com/123456789
```

Rendered as a **facade**: a thumbnail and a play button. The third-party
`<iframe>` is inserted only when a reader presses play.

That is a privacy decision, not a performance one. A YouTube iframe on page load
contacts Google, runs their script and sets cookies for *every* reader of the
page, whether or not they watch. With the facade, a reader who never presses
play is never exposed to the third party at all.

Hosts are an allow-list — YouTube and Vimeo. Anything else renders as a plain
link, because an arbitrary URL in an iframe is an arbitrary document running in
a frame of your site. `frame-src` in the CSP names exactly those two origins,
and the inserted frame is sandboxed.

YouTube uses `youtube-nocookie.com`; there is no reason to prefer the tracking
domain.

## Not built

**Math (KaTeX)** and **Mermaid diagrams** both need a vendored JavaScript
library, and this project has no package manager by design. Adding either means
dropping the minified build (and, for KaTeX, its fonts) into
`public/assets/js/`, adding a `:::math` / `:::mermaid` container to
`Markdown::blocks()`, and widening `font-src` in the CSP for KaTeX. The parser
side is perhaps twenty lines; the vendoring is the actual work.

**OpenAPI/Swagger blocks** are a larger job again — that is a renderer, not a
block.

## Dialogs and tooltips

There are no native `alert`, `confirm` or `prompt` calls anywhere. They are
browser chrome: unstyleable, they announce the origin ("127.0.0.1 says"), and on
a phone they are disorienting.

`public/assets/js/dialog.js` provides `Dialog.alert` / `.confirm` / `.prompt`,
all promise-based, built on the native `<dialog>` element — which gives focus
trapping, Escape, top-layer rendering and a real `::backdrop` without any of it
being hand-rolled. On screens under 520px it becomes a bottom sheet with
full-width buttons, and the input is 16px so iOS does not zoom on focus.

**The entrance animation deliberately does not animate opacity.** A stalled
animation holds its first keyframe, so fading in from 0 leaves an invisible
dialog that still traps focus whenever animations are not advancing — in a
background tab, for instance. Only `transform` is animated, so the worst case is
that the dialog simply appears in place.

`public/assets/js/tooltip.js` replaces `title=`. Use `data-tip="…"` on any
element. It shows on hover after a short delay and on keyboard focus
immediately, flips above the trigger when there is no room below, and is
suppressed entirely on touch devices, where there is no hover and a tooltip just
covers the control you pressed.

It also gives an accessible name to any tooltipped control whose visible text is
not one — a toolbar button labelled `B`, `▦` or `{ }` — while leaving buttons
with descriptive text alone.

## Page icons and the symbol picker

A page can carry an icon, shown beside it in the sidebar and before its
heading. Set it with the button to the left of the title in the editor.

**The icon is its own column (`pages.icon`), not part of the title.** A
character inside the title string ends up in the slug, the `<title>` tag, the
search index, the sitemap and every breadcrumb — places where a decorative
glyph is noise. Keeping it separate means it renders exactly where it should
and nowhere else. It is also language-neutral: one icon for all seven
translations, because an icon means the same thing in every language.

It is marked `aria-hidden` wherever it appears. A screen reader announcing
"rocket Getting started" on every sidebar entry is noise, and the icon carries
nothing the title does not.

### Why not an icon font

Everything the picker offers is a **real Unicode character**. Icon fonts render
their glyphs from Private Use Area codepoints, so the character only looks like
an icon where that font is loaded. In stored text it would be a blank box in
the browser tab, in search results, in the sitemap, in a database dump, and for
any reader without the font — and it would need a vendored font file plus a
`font-src` CSP change. Unicode survives all of that for free.

### The picker elsewhere

Add `data-symbols` to any text input or textarea and it gains a picker button:

```html
<input type="text" data-symbols>
```

It inserts at the caret (not at the end) and fires an `input` event afterwards,
so the editor's unsaved-changes tracking and live preview both notice.
Deliberately NOT applied to slug or password fields — a slug is ASCII by
design, and the server strips anything else.

142 symbols in nine groups, searchable by name. Currency includes ฿ ₩ ₫ ₹ Rp
for the languages this site ships in.
