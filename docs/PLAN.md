# Plan

## Built

- Spaces with public / internal / private visibility and per-space membership,
  managed in `/admin/spaces/{id}/members`
- Page tree: nesting, drag-and-drop reordering, reparenting, drafts, redirects
  on rename
- Markdown editor with live preview, formatting toolbar, paste/drop uploads
- Tabs and code groups (`:::tabs` with `=== Label` panels), callouts
- Server-side syntax highlighting (`Core\Highlight`)
- Revision history with a line diff and restore (restore appends, never deletes)
- Full-text search across every space the reader may see, plus a header
  search-as-you-type box
- Admin: spaces, users, roles, audit log
- Login throttling, session idle/absolute timeouts, audit trail — see
  docs/SECURITY.md
- Light/dark theme, mobile drawer, on-this-page rail
- Seven languages with per-language content, history and search; machine
  translation as an optional driver — see docs/I18N.md
- Content blocks: callouts, tabs/code groups, `:::details`, `:::cards`,
  `:::steps`, and privacy-preserving YouTube/Vimeo embeds
- SEO: per-page descriptions, OG tags, multilingual sitemap.xml, robots.txt
- Maintenance scripts: `rerender`, `gc-assets`, `check-links`
- Sessions in MySQL; uploaded-blob garbage collection

## Next, roughly in order

### 0. The security gaps that matter first

`docs/SECURITY.md` lists them all. The two that should be closed before this is
reachable from the public internet:

- **Password reset.** There is no email path at all today; if the only admin
  forgets their password, recovery is writing a bcrypt hash into the database by
  hand.
- **Rate limiting beyond the login form.** `/api/search` runs a FULLTEXT query
  per call and is anonymous on public spaces.

### 1. WYSIWYG mode

The seam is already there. `page/edit.php` has a Markdown/Rich-text switch (the
second is disabled), and `editor.js` treats the textarea value as the single
source of truth for the document.

To add it: vendor a ProseMirror or TipTap bundle into
`public/assets/js/`, give it a `getValue()`/`setValue()` pair that serialises
to and from the same Markdown string, and have the mode switch swap which
surface owns that string. **Nothing server-side changes** — `save()` posts
Markdown either way, which is why there is no `format` column anywhere.

The work that is genuinely hard is the serialiser round-tripping callouts and
code-block titles without mangling them. Test that first, before the UI.

### 2. Page-level permissions

Currently permissions stop at the space. If a single page needs to be
restricted inside an otherwise readable book, add a nullable
`pages.min_role` and extend `Core\Perm` — but consider whether a second space
is the better answer first, because per-page rules make the sidebar's
"which of these may I see" question much more expensive.

### 3. Search engine swap

Meilisearch or Typesense behind `SearchController::run()` when FULLTEXT stops
being enough — see docs/DATABASE.md for where the line is.

## Deliberately not planned

- **Git sync.** Out of scope by decision. The database is the source of truth.
- **Public signup.** Accounts are created by an admin; that is what keeps a
  self-hosted docs site from turning into an open wiki.
- **Comments.** A docs site that also wants discussion usually wants a forum
  next to it, not threads under every page.
