# Icons

A page icon is the small mark beside a page in the sidebar and above its
heading. It can be one of two different things, and both are supported on
purpose.

| | Vector icon | Emoji |
|---|---|---|
| Stored as | `lucide:rocket` | `🚀` (the literal character) |
| Colour | monochrome, follows `color` | fixed, full colour |
| Follows the accent when active | **yes** | no |
| Looks like a matched set | yes | no |
| Needs an asset | yes (bundled) | no |

The vector form is the one that gives the GitBook look: because the stroke is
`currentColor`, an icon turns blue when its page is the active one and grey when
it is not, and a sidebar of them reads as one designed set. That is the single
behaviour an emoji cannot have, and the reason both forms exist rather than one
replacing the other.

Which form a stored value is comes from the `lucide:` prefix, never from
guessing about the character.

## Where the set comes from

[Lucide](https://lucide.dev) v1.28.0, ISC licence, 2007 icons. It is **vendored**
— committed to this repository, no npm, no CDN, no build step at deploy time.
See `docs/CREDITS.md` for the licence.

Regenerate with:

```bash
php scripts/build-icons.php
```

That writes three files, all committed:

| File | Size | Used by |
|---|---|---|
| `app/Data/lucide-icons.php` | ~400 KB | the server, to inline icons into pages |
| `public/assets/icons/lucide.svg` | ~660 KB | the picker only |
| `public/assets/icons/lucide-index.json` | ~156 KB | the picker's search |

The build script rejects any icon whose markup contains an element outside a
small shape whitelist, or anything script-shaped. The files are vendored input,
but they are still input, and this markup is deliberately printed **raw** — a
`<script>` reaching the map would be injected into every page using that icon
and no output escaping would catch it.

### Why both a PHP map and a sprite

Readers get icons **inlined** from the PHP map. A page shows perhaps thirty
distinct icons, which measured at ~2.8 KB of extra markup (~400 bytes gzipped) —
far less than making every reader fetch a 660 KB sprite to use 1.5% of it. HTML
here is sent `no-store`, since it carries a CSP nonce and the signed-in user, so
a sprite would not amortise the way it does on a cacheable site.

The **picker** genuinely needs all 2007 at once, and only signed-in authors ever
open it. That is the one place a single cacheable sprite is the right trade.

Loading the PHP map costs ~1.25 ms and it is loaded lazily, so a request that
draws no icons does not pay for it.

## The emoji set

The full CLDR emoji list — **1,907 emoji**, the same names and keywords a phone
keyboard searches — plus ~100 typographic characters (→ ± € ⌘ —) that are not
emoji and so are not in the emoji file. Source is
[emojibase](https://emojibase.dev) (MIT), regenerated with:

```bash
php scripts/build-emoji.php
```

Written to `public/assets/icons/emoji.json` (~128 KB), fetched lazily by the
picker so a reader who never opens it never downloads it.

Emoji newer than **Emoji 15.1** are excluded (`MAX_EMOJI_VERSION` in the build
script) — 16 characters at present. This is not squeamishness about new emoji: a
picker offering a character the reader's system has no glyph for shows an empty
box, and a grid of boxes looks like the feature is broken rather than like the
font is old. Raise the constant as fonts catch up.

The list is **flat and uncategorised**. It is in Unicode order, which runs
smileys → people → animals → food → travel → objects → symbols → flags, so it
still reads in a familiar sequence; with ~2,000 entries a category heading
scrolls off screen before you have finished looking at the things it labels,
which makes it worse than no heading at all. Search does the work instead.

## The picker

Two tabs — Icons and Emoji. The Icons tab appears **only** where the target can
store a name, which today means the page icon field. A text input holds text, so
offering it there would let you insert the literal string `lucide:rocket` into a
title.

Search on both tabs matches **word prefixes**, not substrings, and ranks exact
name matches first. Plain substring matching looked fine until tried: "cat"
returned fifty-odd emoji because it appears inside "intoxicated" and
"delicate", and "rocket" listed three astronauts before 🚀.

Search covers each icon's name *and* its Lucide tags, so "warning" finds
`circle-alert` and "database" finds all ten `database-*` icons.

`symbols.js` locates the sprite and the index from **its own script URL**
(`document.currentScript.src`), not from a global the server set. The global
approach broke in exactly one place: the editor is a bare view that defines
`window.ED.base` rather than `window.APP_BASE`, so the base silently became ''
and the picker reported "Icons could not be loaded" for authors on any install
not mounted at the domain root. Self-location works under any mount with
nothing to configure.

### Loading

Four things keep it responsive:

- **Prefetch on intent.** Hovering or focusing anything that opens the picker
  starts both fetches, so the data is usually there before the click. This is
  where the waiting actually was; it costs readers nothing, because a reader who
  never approaches the button never downloads the 280 KB.
- **Only the visible tab is built.** The other renders when you switch to it.
- **Search is debounced** at 90 ms and refilters only the visible tab.
- **A spinner** covers the gap on a cold load.

All matches are then drawn **in one pass**, deliberately. Three attempts at
drawing incrementally have failed the same way — an `IntersectionObserver` that
never fired, a scroll handler rooted on the wrong element, and a two-stage draw
whose tail timer is throttled whenever the tab is not in the foreground. Each
left a grid holding a few hundred of two thousand entries and looking exactly
like a complete list, which is the one outcome a picker must never produce: you
do not go looking for icons you have no reason to think exist.

One pass is ~80 ms for 2,007 icons, measured. That cost is real and worth
paying, because it cannot be partially right.

## Chrome icons

The short names used by buttons and navigation — `icon('trash')`, `icon('caretR')`
— are **aliases** onto the same Lucide set, listed in `Core\Icon::ALIAS`. These
used to be two dozen SVG paths pasted into `app/helpers.php` by hand; folding
them in means the app has one icon vocabulary rather than two that drift apart.

Everything renders at `stroke-width: 1.8` rather than Lucide's native `2`, so
the picker previews an icon exactly as the page will draw it.

## Validation and storage

`pages.icon` is `VARCHAR(64)`. The longest bundled name is 35 characters, which
with the prefix is 42; the literal form needs far less.

`Core\Slug::icon()` is the only way a value gets stored:

- A `lucide:` value is checked **against the set**, not merely pattern-matched,
  so a typo or an icon retired upstream is refused while the author is looking
  at the picker rather than rendering as nothing for ever after.
- A literal value must consist only of symbols, combining marks, joiners and
  skin-tone modifiers. Anything containing a letter or ASCII markup is refused
  outright rather than filtered — a half-stripped icon is a silent corruption of
  what the author typed.

Emoji that need several codepoints work: ZWJ families, skin tones, flags, and
keycaps are all preserved.

## Known gaps

- A rejected icon is cleared **silently**. In practice you use the picker, which
  can only produce valid values, but typing a letter into the field by hand
  empties it without saying why.
- You cannot *search for* a page by its emoji — `FULLTEXT` does not tokenise
  emoji. Vector icons are not indexed either; they are decoration.
