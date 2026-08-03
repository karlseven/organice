# Architecture

## Shape of a request

```
public/index.php          front controller
  └ config/config.php     env, autoloader, session, security headers
  └ route table           [method, regex, handler, access]
      └ Csrf::check()     every POST, centrally — controllers cannot forget
      └ access gate       false | 'auth' | 'admin'
      └ Controller        loads data via Core\DB (stored procedures only)
          └ Core\View     app/Views/<template>.php inside layout.php
```

There is no framework and no dependency directory. The autoloader in
`config/config.php` maps `Core\Foo` to `app/Core/Foo.php` and
`Controllers\Bar` to `app/Controllers/Bar.php`; that is the entire mechanism.

## Route table

Routes are an ordered array in `public/index.php`. Order matters: the space
routes are last because their patterns are the broadest, and a page slugged
`admin` must not shadow the admin route.

Access levels are declared per route, not checked inside controllers. The
front controller runs the CSRF check on every POST before dispatch, so a new
POST route is protected by existing, not by remembering.

Editing routes address pages by **id**, reading routes by **path**. A page
keeps its id across renames and moves, so an editor tab left open overnight
still points at the right page; a path is the reader-facing, shareable form.

## Data access

Every query is a stored procedure called through `Core\DB`. The app's MySQL
user has `EXECUTE` and nothing else (`database/setup.sql`), so this is enforced
by the server, not by convention — an injection that got past the prepared
statements would still have no `SELECT` privilege to use.

`Core\DB::proc()` refuses any name that is not `/^sp_[a-z0-9_]+$/`.

## The page tree

`pages` is an adjacency list (`parent_id`, `position`) **plus** a materialized
`path`. The adjacency list makes reordering and reparenting cheap; the path
makes a URL resolve in one indexed lookup instead of a walk down the tree.

`path` is derived and never authored. `sp_page_paths_rebuild` recomputes it for
a whole space, level by level, after any rename or move. Uniqueness is enforced
on `(space_id, path)` rather than `(space_id, parent_id, slug)` because MySQL
allows many NULLs in a unique index, which would let two root pages collide.

`Core\Tree` turns the flat rows into the nested sidebar, the reading-order
sequence for prev/next, and the breadcrumb — one query, three shapes.

Nesting is reached two ways, both landing on the same `parent_id`:

- the **`+` on each sidebar row**, which posts `parent_id` to `POST /api/pages`
  and opens the editor on the new child;
- **dragging** a page onto the middle third of another row (the top and bottom
  quarters reorder among siblings instead) — `POST /api/pages/{id}/move`.

`parent_id` arrives in a request body in both cases, so both handlers check it
is a page in the space being written to. `sp_page_create` reads the parent's
path without asking whose space it is, so without that check a crafted request
could hang a page off a parent in a space the caller cannot read. Both also
clamp it to `>= 0`: the procedure parameter is `UNSIGNED`, and a negative value
otherwise surfaces as a raw MySQL range error rather than a 404.

## Rendering

`Core\Markdown` is a hand-written CommonMark subset plus `:::` containers
(callouts and tabs). It is run at **save** time and the HTML is stored in
`page_revisions.content_html`, so viewing a page never runs the parser.
`Core\Highlight` tokenizes fenced code in the same pass, for the same reason —
highlighting costs one save, not one page view, and the markup is in the HTML
that search engines and reader-mode see.

**When either of those changes what they emit, run `php scripts/rerender.php`.**
Existing pages keep their old cached markup until something rewrites it; the
symptom of forgetting is a new feature that works on pages you edit and nowhere
else.

Raw HTML in the source is escaped, never passed through. That is what makes
storing rendered HTML safe: there is no path by which author input reaches the
page as markup, so no post-hoc sanitiser is needed. If pass-through HTML is
ever wanted it must go through an allow-list in the parser — not by relaxing
that rule.

The same call produces the table of contents and the plain-text body for the
search index, so a save cannot store HTML that disagrees with its own index.

## Permissions

Two independent axes, combined in `Core\Perm`:

- **Site role** on `users`: `admin` (everything), `editor`, `viewer`.
- **Space membership** in `space_members`: `owner`, `editor`, `viewer`.

Read access additionally depends on the space's `visibility`
(`public` / `internal` / `private`). The full ladder is written out in
`Perm::canRead()`, `sp_spaces_visible` and `sp_search` — if it changes, change
all three.

A space the visitor may not read returns **404, not 403**: a 403 confirms the
space exists, which is what someone probing for it wants to learn.

The rest of the security posture — throttling, sessions, audit, and the known
gaps — is in `docs/SECURITY.md`.

## Languages

The default language is served without a URL prefix and every other with one.
`pages` is language-neutral; `page_locales` points at each language's current
revision, and `page_revisions` carries a `lang` so histories stay independent.

Two separate `lang` concepts exist and deliberately have different spellings:
the URL prefix (and `?setlang=`) is the language being READ, `?lang=` is the
language being EDITED. Full detail in docs/I18N.md.

## Page ordering

`sp_page_move` takes an **intent** (`first`, `last`, or `after` a named
sibling), never a raw position integer. A raw integer is ambiguous the moment
two siblings share one — "position 3" cannot say whether it means before or
after the page already there, and drag-and-drop produces exactly that collision
on every drop. After the move, siblings are renumbered `1..n`, so positions
never drift and never need a migration to tidy up.

The sidebar's drag handler translates "drop above X" into "after whatever
precedes X", because those three intents are the only statements that stay
unambiguous.

## Uploads

Files go to `storage/uploads/<aa>/<bb>/<sha256>`, outside the webroot, and are
served by `AssetController` after a visibility check. The type is decided by
`finfo` reading the bytes, never by the browser-supplied `Content-Type`, and
only an allow-list of types is accepted. SVG is served under a sandboxing CSP
because an SVG opened directly is a document in this origin.

## Security headers

`config/config.php` sends the CSP. `style-src` has no `'unsafe-inline'`, which
means **style attributes do not work** — dynamic styling goes through
`css_add()` and the nonced `<style>` block in the layout. This has bitten
before; if something styled from PHP is not appearing, that is why.

## Front end

Two files, no build step. `app.js` is progressive enhancement over pages that
already work without it (theme, drawer, search-as-you-type, copy buttons, TOC
highlighting). `editor.js` owns the editor.

Static assets are requested as `?v=<filemtime>` via `asset()`, which is what
makes the one-year cache header in `public/.htaccess` safe.
