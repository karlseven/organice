# Plan

## Built

- Spaces with public / internal / private visibility and per-space membership,
  managed in `/admin/spaces/{id}/members`
- Page tree: nesting (the `+` on a sidebar row, or drag onto another page),
  drag-and-drop reordering, reparenting, drafts, redirects on rename
- Markdown editor with live preview, formatting toolbar, paste/drop uploads,
  an explicit undo/redo history, and tooltips on every control
- Page icons: 2,007 bundled Lucide icons and 1,907 emoji, searchable, self-hosted
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

`docs/SECURITY.md` lists them all. The one that should be closed before this is
reachable from the public internet:

- **Password reset.** There is no email path at all today. Recovery for a
  locked-out admin is `scripts/set-credentials.php` over SSH, which is fine for
  one operator and not fine for a team.

Rate limiting beyond the login form is **done** — `/search`, `/api/search`,
`/api/preview` and `/api/pages/*/translate` are all limited per address, and
both limiters fail open so that losing the limiter's own table cannot take the
site down.

### 1. WYSIWYG mode — built, then removed

Not a plan item any more. It was implemented and taken out again at the
maintainer's request; `docs/EDITOR.md` records what it did, why it went, and the
two rules to keep if anyone revisits it. The mode switch in `page/edit.php` is
now Markdown/Preview, not Markdown/Rich-text.

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
