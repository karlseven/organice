# organice

A self-hosted documentation site. Spaces, a nested page tree, Markdown with live
preview, revision history with diffs, full-text search, uploads, and a UI that
speaks seven languages.

**Raw PHP and MySQL. No Composer, no npm, no build step, no framework.** Copy
the folder to a server, create the database, and it runs. Deploying an update is
`git pull` — there is nothing to compile and no dependency tree to audit.

```
PHP 7.4 – 8.x  ·  MySQL 8.0+  ·  Apache or nginx  ·  ~0 dependencies
```

---

## Contents

- [Why this instead of GitBook](#why-this-instead-of-gitbook)
- [Features](#features)
- [Requirements](#requirements)
- [Install](#install)
- [Writing](#writing)
- [Languages](#languages)
- [Project layout](#project-layout)
- [Database](#database)
- [Maintenance scripts](#maintenance-scripts)
- [Security](#security)
- [What it deliberately does not do](#what-it-deliberately-does-not-do)
- [Documentation](#documentation)
- [Licence and credits](#licence-and-credits)

---

## Why this instead of GitBook

organice is shaped like GitBook — sidebar tree, page-level table of contents,
callouts, tabbed code groups — because that layout works. What differs is
everything underneath.

| | **organice** | **GitBook** |
|---|---|---|
| **Where content lives** | Your MySQL database, on your server | GitBook's cloud |
| **Cost** | Your hosting bill | Free tier, then per-editor per-month |
| **Source of truth** | The database. Edited in the browser. | A Git repo, synced two ways |
| **Editing** | Markdown textarea, live preview beside it | Block-based WYSIWYG |
| **Git sync** | **None.** See below. | Central feature |
| **Search** | MySQL FULLTEXT, with an ngram index for CJK | Hosted search index |
| **Translations** | Built in — per-language pages, one URL tree, optional machine-translation assist | Separate "variants", largely manual |
| **Access control** | Per-space roles in your own user table | Per-org seats |
| **Customisation** | It's your PHP. Change any of it. | Themes and settings |
| **Runs offline / air-gapped** | Yes | No |
| **Your data if the vendor dies** | Unaffected | A migration problem |

**The Git question, answered honestly.** organice has no Git sync and won't get
it. Two-way sync between a database and a repo is the hardest part of GitBook,
and getting it slightly wrong loses people's writing. If your docs must live in
a repo next to the code they describe, use GitBook, Docusaurus or MkDocs — that
is what they are for, and this is the wrong tool. organice is for docs that are
their own thing, written by people who should not have to learn a branching
model to fix a typo.

**Pick organice when** you want your documentation on hardware you control, in a
database you can back up with `mysqldump`, with no per-seat pricing, no vendor,
and no build pipeline — and you are comfortable running a PHP site.

**Pick something else when** docs-as-code and pull-request review matter more
than any of that.

---

## Features

**Structure**
- **Spaces** — independent documentation sets, each with its own tree, members
  and accent colour. Or run in single-space mode, where the space disappears
  from the UI entirely and the site is one manual.
- **Nested page tree** — add a subpage with the `+` on any sidebar row, or drag
  a page onto another to nest it; drag between rows to reorder
- **Page icons** — a searchable picker over 2,007 bundled Lucide line icons and
  1,907 emoji, all served from your own server
- **Redirects** kept automatically when a page is renamed or moved, so published
  links do not rot

**Writing**
- Markdown with a live preview beside it, rendered by the same parser the
  published page uses — the preview cannot lie to you
- **Undo/redo that survives everything** — Ctrl+Z, Ctrl+Y, Ctrl+Shift+Z, covering
  toolbar edits, uploads and revision loads as well as typing
- Toolbar with tooltips, Ctrl+B / Ctrl+I / Ctrl+K, Tab to indent
- The preview **keeps the tab you had open** while you edit a tab group
- **Drag, drop or paste an image** straight into the text
- Revision history with a **side-by-side diff** and one-click restore
- Server-side syntax highlighting — no JS highlighter shipped to readers

**Content blocks** (see `docs/BLOCKS.md`)
- Callouts — note, tip, warning, danger
- Tabs and code groups
- Collapsible sections, cards, numbered steps, file attachments
- **Privacy-preserving embeds** — a YouTube or Vimeo embed renders as a
  thumbnail; nothing contacts the video host until a reader presses play

**Reading**
- Full-text search with an **ngram index**, so Japanese, Chinese, Korean and Thai
  are searchable rather than nominally supported
- Per-page table of contents that highlights the section being read
- `sitemap.xml`, `robots.txt`, per-page meta descriptions and Open Graph tags
- Light and dark, following the reader's system setting

**Running it**
- Admin screens for users, spaces, members and site settings
- White-labelling: your logo, favicon, site name and accent colour
- Rate-limited login and search, an audit log, and a `security-check.php` you can
  gate a deploy on

---

## Requirements

| | |
|---|---|
| **PHP** | 7.4 or newer. Tested on 7.4.33 and 8.2.12. |
| **Extensions** | `pdo_mysql`, `mbstring`, `fileinfo`, `json` |
| **MySQL** | 8.0 or newer — the schema needs `utf8mb4_0900_ai_ci` and the ngram FULLTEXT parser. MariaDB is **not** supported. |
| **Web server** | Apache with `mod_rewrite`, or nginx |

`config/config.php` checks the PHP version and every extension before anything
else runs, and fails with a sentence telling you the `apt` package to install —
rather than a fatal error deep inside an unrelated file.

Enable **opcache** in production. The bundled icon set is a ~400 KB PHP array;
opcache compiles it once instead of on every request.

---

## Install

### 1. Get the files

```bash
git clone https://github.com/you/organice.git
cd organice
cp .env.example .env
```

Edit `.env` and set at minimum `DB_NAME`, `DB_USER`, `DB_PASS`.

### 2. Create the database

**Linux / macOS**

```bash
sh database/install.sh
```

**Windows**

```powershell
powershell -File database/install.ps1
```

Either script reads `.env`, creates the database with the correct collation, and
runs `schema.sql` then `procedures.sql` against it.

**By hand** — note that you must name the database. Neither `.sql` file contains
a `USE` statement (that is what lets you install into a database of any name),
so piping them in without `-D` fails with *No database selected*:

```bash
mysql -u root -p -e "CREATE DATABASE organice CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p -D organice < database/schema.sql
mysql -u root -p -D organice < database/procedures.sql
```

> **The order is not arbitrary.** `schema.sql` sets the database's default
> collation, and stored procedures capture that default at `CREATE` time. Create
> them against a database defaulting to something else — `utf8mb4_unicode_ci`, as
> several control panels still do — and every lookup by slug, path or email dies
> at runtime with `ERROR 1267: Illegal mix of collations`, on the first page
> load, looking exactly like an application bug. If you ever see it: fix the
> database default, then **re-run `procedures.sql`**.

### 3. Create the first admin

```bash
php scripts/seed.php you@example.com "a-long-password"
```

This also creates a demo space whose pages document the Markdown this site
supports. Safe to re-run — it stops if any user already exists.

### 4. Run it

```bash
php -S localhost:8080 -t public public/index.php
```

Open <http://localhost:8080> and sign in.

> The trailing `public/index.php` matters. Without it PHP's built-in server
> handles any path with a file extension itself and 404s when the file is
> missing, so `/sitemap.xml` and `/robots.txt` never reach the front controller.
> Apache and nginx do not need this — their rewrite rules already do it.

### 5. Put it on a real server

Full instructions, both layouts, in **`docs/DEPLOYMENT.md`**. The short version:

- **Virtual host** — point DocumentRoot at `public/`, leave `APP_BASE=` blank.
  Best isolation: the source is not under the web root at all.
- **Subdirectory** (`https://example.com/wiki/`) — keep the shipped root
  `.htaccess` and set `APP_BASE=/wiki` in `.env`. The folder name and `APP_BASE`
  must match; nothing else hardcodes it.

Then, before you open it up:

```bash
php scripts/security-check.php
```

It reads the source, touches nothing, needs no server, and exits non-zero — so
it can gate a deploy. Most of what it catches is a deployment mistake rather
than a coding one.

---

## Writing

Ordinary Markdown, plus the blocks below. Full syntax in `docs/BLOCKS.md`, and
the demo space `seed.php` creates has a live example of every one.

````markdown
> [!TIP]
> Callouts come in note, tip, warning and danger.

:::tabs
== npm
```bash
npm install
```
== composer
```bash
composer install
```
:::

:::details Click to expand
Hidden until asked for.
:::

:::steps
1. First thing
2. Second thing
:::
````

Uploads are stored outside the web root and served through a controller that
checks permissions — never as static files. Drop, paste or browse for them.

---

## Languages

The interface ships in **English, Indonesian, Japanese, Korean, Thai, Vietnamese
and Chinese**. Content is multilingual too, and separately: one page tree, one
set of URLs, a translation per language.

- URLs are locale-prefixed — `/th/installation` — with the unprefixed path
  serving the default language
- The editor edits **one language at a time**; the reading language and the
  language being edited are deliberately independent
- Optional machine-translation assist via Google Cloud Translation or a
  self-hosted LibreTranslate, producing a **draft** for a person to review — set
  `MT_KEY` in `.env`, choose the driver in admin settings
- Search handles CJK and Thai through a separate ngram-indexed table, because
  word-boundary FULLTEXT finds nothing in languages without spaces

See `docs/I18N.md` before touching anything language-related.

---

## Project layout

```
organice/
├── app/
│   ├── Controllers/      one per area — no SQL in any of them
│   ├── Core/             DB, Auth, Perm, Session, Markdown, Highlight, Tree,
│   │                     Uploader, Icon, Slug, I18n, Translator, Embed, HttpError
│   ├── Data/             generated icon and emoji maps (committed, not built on deploy)
│   ├── Lang/             en, id, ja, ko, th, vi, zh — UI string catalogues
│   ├── Views/            plain PHP templates, no template engine
│   ├── helpers.php       escaping, URLs, CSP-nonced inline CSS
│   └── polyfill.php      str_contains / str_starts_with / str_ends_with for 7.4
├── config/config.php     bootstrap: version guards, .env, autoloader, session, CSP
├── database/
│   ├── schema.sql        15 tables
│   ├── procedures.sql    55 stored procedures — every query in the app
│   ├── setup.sql         optional least-privilege DB user
│   ├── install.sh        installer (Linux/macOS)
│   └── install.ps1       installer (Windows)
├── docs/                 ten guides — see below
├── public/               THE ONLY WEB-EXPOSED DIRECTORY
│   ├── index.php         front controller — the single entry point
│   ├── .htaccess         rewrite, compression, caching, security headers
│   └── assets/           css, js, bundled Lucide sprite and emoji data
├── scripts/              seed, set-credentials, rerender, gc-assets,
│                         check-links, build-icons, build-emoji, security-check
├── storage/              NOT web-reachable — uploads and branding live here
├── .env                  secrets. Never committed.
├── .env.example          template
└── .htaccess             only for subdirectory installs; routes into public/
```

Two rules hold everywhere in this codebase:

1. **Controllers contain no SQL.** Every query is a stored procedure called
   through `Core\DB::proc()`, which refuses any name not matching
   `/^sp_[a-z0-9_]+$/`.
2. **Only `public/` is reachable over HTTP.** Everything else is either above the
   document root, or refused by the root `.htaccess`.

---

## Database

15 tables, 55 stored procedures. Reasoning behind each in `docs/DATABASE.md`.

| | |
|---|---|
| `users`, `sessions`, `login_attempts` | accounts and sign-in; sessions live in MySQL, not on disk, so several web servers can share them |
| `spaces`, `space_members` | documentation sets and per-space roles |
| `pages`, `page_locales`, `page_revisions` | the tree, one row per language, full history |
| `page_search`, `page_search_cjk` | FULLTEXT — the second with an ngram parser |
| `assets` | uploaded files, content-addressed by hash |
| `redirects` | old paths kept alive after a rename |
| `settings`, `audit_log`, `rate_limits` | site configuration, an admin action trail, throttling |

**Give the application user `EXECUTE` and nothing else.** Since every query goes
through a procedure, the app never needs table rights — which means SQL
injection has nothing to reach even if a bug let one through. `database/setup.sql`
sets this up:

```sql
CREATE USER 'organice_app'@'localhost' IDENTIFIED BY '<long random>';
GRANT EXECUTE ON organice.* TO 'organice_app'@'localhost';
```

---

## Maintenance scripts

```bash
php scripts/seed.php <email> <password>       # first admin + demo space
php scripts/set-credentials.php <email> --password=…   # recover a locked-out admin
php scripts/security-check.php                # pre-deploy audit, exits non-zero
php scripts/gc-assets.php [--delete]          # sweep uploads no page references
php scripts/check-links.php                   # report internal links that no longer resolve
php scripts/rerender.php                      # re-render every page after a parser change
php scripts/build-icons.php                   # regenerate the bundled Lucide set
php scripts/build-emoji.php                   # regenerate the bundled emoji list
```

`gc-assets.php` is the one worth scheduling. Deleting a page does **not** delete
its uploaded blobs — deliberately, since the same file may be used by several
pages and an old revision may still reference it.

`rerender.php` matters after any change to `Core\Markdown` or `Core\Highlight`:
rendered HTML is cached at save time, so existing pages keep the old markup until
something rewrites them.

The two `build-*` scripts are for maintainers only. Their output is committed —
deployment never runs a build.

---

## Security

Details and the threat model in `docs/SECURITY.md`. In brief:

- **Every query is a stored procedure**, called through a whitelist. The app's DB
  user has `EXECUTE` only.
- **CSP with a per-request nonce** on both `script-src` and `style-src`, and no
  `unsafe-inline` anywhere — including no `style=""` attributes.
- **All author input is escaped by the Markdown renderer.** No raw HTML passes
  through, in the published page or the preview.
- **Uploads are stored outside the web root**, content-addressed, MIME-sniffed,
  and served through a permission-checking controller — never executed.
- **Sessions in MySQL** with strict mode, a row lock, and regeneration on login.
- **CSRF tokens** on every state-changing request; **rate limiting** on login and
  search, failing *open* so a broken limiter cannot take the site down.
- `X-Powered-By` removed, HSTS when actually over TLS, `frame-ancestors 'none'`.

Found a vulnerability? Open a security advisory rather than a public issue.

---

## What it deliberately does not do

Stated plainly, so you can rule it out fast:

- **No Git sync**, and none planned — see [above](#why-this-instead-of-gitbook)
- **No public signup.** Accounts are created in `/admin`.
- **No comments** or reader accounts
- **No password reset**, because there is no email path at all. A locked-out
  admin is recovered over SSH with `scripts/set-credentials.php`.
- **No 2FA**
- **No plugin system.** It is your PHP; edit it.
- **MariaDB is not supported** — the schema needs MySQL 8 collations and the
  ngram parser.

---

## Documentation

| File | What it covers |
|---|---|
| `docs/ARCHITECTURE.md` | how a request flows; read before changing anything structural |
| `docs/DATABASE.md` | every table and why it looks like that |
| `docs/DEPLOYMENT.md` | VPS setup, both layouts, Apache and nginx, the pre-flight checklist |
| `docs/SECURITY.md` | threat model and every protection, with its limits |
| `docs/BLOCKS.md` | the Markdown extensions authors can use |
| `docs/EDITOR.md` | the editor, the Markdown round trip, and why rich text was removed |
| `docs/I18N.md` | locale routing, translations, search in CJK and Thai |
| `docs/ICONS.md` | page icons, the bundled Lucide set, the picker |
| `docs/CREDITS.md` | vendored third-party material and its licences |
| `docs/PLAN.md` | roadmap |

---

## Licence and credits

Bundled third-party material, with licences, is recorded in `docs/CREDITS.md`:

- **Lucide** icons — ISC (`public/assets/icons/LICENSE-lucide.txt`)
- **Unicode CLDR** emoji annotations — Unicode licence

Both are vendored as generated data files. Nothing is fetched at runtime, and no
reader's browser ever contacts a third party — except a video host, after they
press play.
