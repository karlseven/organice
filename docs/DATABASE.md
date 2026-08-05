# Database

MySQL 8.0+, InnoDB, `utf8mb4_0900_ai_ci` throughout.

## Tables

| Table | Holds |
| --- | --- |
| `users` | Accounts. No public signup — created in `/admin`. |
| `spaces` | One book each. Carries `visibility` and an accent colour. |
| `space_members` | Per-space roles, additive on top of the site role. |
| `pages` | The navigation tree: adjacency list + materialized `path`. |
| `page_revisions` | Append-only history. Markdown **and** rendered HTML. |
| `page_search` | One row per page, `FULLTEXT(title, body_text)`. |
| `redirects` | Old path → page, written on every rename and move. |
| `assets` | Uploaded file metadata; the bytes live in `storage/uploads`. `folder` is a virtual path for the media library — see docs/EDITOR.md. |
| `settings` | Site-wide key/value. |

## Why some of it looks the way it does

**`pages.path` is derived, not authored.** It exists so a URL resolves in one
indexed lookup. `sp_page_paths_rebuild` recomputes it for a whole space after
any structural change, iteratively by depth level — not with a recursive CTE,
because MySQL will not let an `UPDATE` target a table a CTE in the same
statement reads from. The level-by-level join also guarantees no row is both a
source and a target within one statement.

Its one sharp edge: the unique key on `(space_id, path)` is enforced per
statement, so swapping two sibling slugs in a single call would collide
mid-rebuild. `Core\Slug::unique()` settles slugs against their siblings
*before* any of this runs, which is why the collision never reaches SQL.

**`page_revisions` stores rendered HTML.** Viewing a page must not run the
Markdown parser. `sp_revision_create` writes the revision, repoints
`pages.current_revision_id`, and refreshes `page_search` in one call so those
three cannot drift apart.

**`page_search` is a separate table.** The FULLTEXT index is then only touched
when text actually changes — reordering pages or flipping a space's visibility
does not rebuild it. `body_text` is Markdown stripped of syntax with code
blocks removed, so a search for `user` finds the page about users rather than
every page with a `user` variable in a snippet.

**`redirects` is not optional.** Reorganising a book is the one mistake that
cannot be undone after the fact, because you cannot know what people had
linked. Every rename and move writes a row here.

**`assets` deduplicates on `(space_id, sha256)`.** Deleting a space leaves the
blobs on disk on purpose: they are content-addressed and may be shared, and a
sweeper is safer than a cascading unlink inside a web request.

## Procedures

All access goes through `sp_*` procedures — see `database/procedures.sql`. The
app user has `EXECUTE` only.

Two conventions worth knowing before you add one:

- A user id of **0** means "not signed in". Procedures take `p_user` and
  `p_is_admin` explicitly rather than deriving them, so the visibility rules
  read identically everywhere they appear.
- Anything that returns a new id ends with `SELECT ... AS id`, which is what
  `DB::procOne()` reads.

The visibility ladder is spelled out in three places — `sp_spaces_visible`,
`sp_search`, and `Core\Perm::canRead()`. If it changes, change all three.

## Scaling notes

MySQL FULLTEXT in boolean mode is comfortable to roughly the tens of thousands
of pages. Past that, or if you need CJK tokenisation or typo tolerance, replace
`sp_search` with a call to a dedicated engine; nothing above
`SearchController::run()` knows how search is implemented.

The default minimum token length (`innodb_ft_min_token_size`, 3) is why
`SearchController` drops shorter words — they would match nothing and drag the
whole query down with them.
