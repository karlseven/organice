-- organice — schema
-- MySQL 8.0+. Run with database/setup.sql, or database/install.ps1.
-- See docs/DATABASE.md for the reasoning behind each table.

SET NAMES utf8mb4;

-- Align the DATABASE default with the tables below.
--
-- Not cosmetic. A stored procedure's parameters and local variables take the
-- database's default collation at CREATE time, while every column here is
-- explicitly utf8mb4_0900_ai_ci. If the database was created with a different
-- default — utf8mb4_unicode_ci, say, which older tools and control panels
-- still pick — then every `column = p_param` comparison inside
-- database/procedures.sql fails at runtime with:
--
--   ERROR 1267: Illegal mix of collations ... for operation '='
--
-- The failure surfaces on the first lookup by slug, path or email, not at
-- install time, so it looks like an application bug rather than a setup one.
-- Running this makes the install self-correcting whatever the database was
-- created as. Procedures must be (re)created AFTER this statement.
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS page_search_cjk;
DROP TABLE IF EXISTS page_search;
DROP TABLE IF EXISTS page_locales;
DROP TABLE IF EXISTS page_revisions;
DROP TABLE IF EXISTS redirects;
DROP TABLE IF EXISTS assets;
DROP TABLE IF EXISTS pages;
DROP TABLE IF EXISTS space_members;
DROP TABLE IF EXISTS spaces;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
-- There is no public signup: an admin creates accounts. `role` is the SITE
-- role; per-space rights live in space_members and are strictly additive on
-- top of it (an admin can always edit everything).
CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190)    NOT NULL,
  username      VARCHAR(60)     NOT NULL,
  display_name  VARCHAR(120)    NOT NULL,
  password_hash VARCHAR(255)    NOT NULL,
  role          ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  last_login_at DATETIME        NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- spaces  (a "book" — one product's docs)
-- ---------------------------------------------------------------------------
-- visibility:
--   public   — readable by anyone, signed in or not
--   internal — readable by any signed-in user
--   private  — readable only by users listed in space_members
CREATE TABLE spaces (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(80)     NOT NULL,
  title       VARCHAR(160)    NOT NULL,
  description VARCHAR(400)    NOT NULL DEFAULT '',
  visibility  ENUM('public','internal','private') NOT NULL DEFAULT 'public',
  accent      CHAR(7)         NOT NULL DEFAULT '#5b7cfa',
  position    INT             NOT NULL DEFAULT 0,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_spaces_slug (slug),
  KEY ix_spaces_position (position),
  CONSTRAINT fk_spaces_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE space_members (
  space_id BIGINT UNSIGNED NOT NULL,
  user_id  BIGINT UNSIGNED NOT NULL,
  role     ENUM('owner','editor','viewer') NOT NULL DEFAULT 'viewer',
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id, user_id),
  KEY ix_members_user (user_id),
  CONSTRAINT fk_members_space FOREIGN KEY (space_id) REFERENCES spaces (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user  FOREIGN KEY (user_id)  REFERENCES users  (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- pages  (the navigation tree)
-- ---------------------------------------------------------------------------
-- Adjacency list (parent_id + position) so reordering is cheap, PLUS a
-- materialized `path` so a URL resolves in ONE indexed lookup instead of
-- walking the tree segment by segment. `path` is derived, never authored —
-- sp_page_paths_rebuild recomputes it for the whole space after any rename or
-- move.
--
-- Uniqueness is enforced on (space_id, path) rather than
-- (space_id, parent_id, slug): MySQL permits many NULLs in a unique index, so
-- the latter would let two root pages share a slug.
-- `slug` and `path` are language-NEUTRAL and shared by every translation. The
-- language lives in a URL prefix (/th/s/handbook/writing) rather than in the
-- path, which means a link translates by swapping one segment, a reader can
-- switch language without losing their place, and the redirects table does not
-- have to be maintained per language.
--
-- `title` here is the default-language title, kept for the admin screens and as
-- the fallback when a translation has none. Per-language titles live in
-- page_locales.
CREATE TABLE pages (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id            BIGINT UNSIGNED NOT NULL,
  parent_id           BIGINT UNSIGNED NULL,
  slug                VARCHAR(120)    NOT NULL,
  title               VARCHAR(200)    NOT NULL,
  /* A single emoji or symbol shown beside the page in the sidebar and above
     its heading. Its OWN column rather than part of the title, because a
     character sitting inside the title string ends up in the slug, the <title>
     tag, the search index, the sitemap and every breadcrumb — places where a
     decorative glyph is noise at best. Kept language-neutral: an icon means the
     same thing in Thai as in English.

     Holds one of two forms, distinguished by the prefix (see Core\Icon):
       'lucide:rocket'  a named vector icon from the bundled set
       '🚀'             a literal Unicode character

     64 chars: the longest bundled icon name is 35 ('square-centerline-dashed-
     horizontal'), which with the 'lucide:' prefix is 42. The literal form needs
     far less — a ZWJ family is seven codepoints — so the vector form sets the
     width. This was VARCHAR(24), sized for characters alone, and a name longer
     than 17 would have been silently truncated into a different icon. */
  icon                VARCHAR(64)     NOT NULL DEFAULT '',
  path                VARCHAR(700)    NOT NULL,
  depth               TINYINT UNSIGNED NOT NULL DEFAULT 0,
  position            INT             NOT NULL DEFAULT 0,
  -- the master gate: a draft page does not exist in ANY language
  status              ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_path (space_id, path),
  KEY ix_pages_tree (space_id, parent_id, position),
  CONSTRAINT fk_pages_space  FOREIGN KEY (space_id)  REFERENCES spaces (id) ON DELETE CASCADE,
  -- deleting a parent takes its whole subtree with it; the UI confirms first
  CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages  (id) ON DELETE CASCADE,
  CONSTRAINT fk_pages_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- page_revisions  (append-only history)
-- ---------------------------------------------------------------------------
-- Markdown is the source of truth; content_html is the render cached at save
-- time so a page view never runs the parser. A future WYSIWYG editor writes
-- the same content_md, which is why the editor mode is not stored here.
-- Each language has its own independent history: editing the Thai page does not
-- create a revision of the English one, and reverting one never touches
-- another.
CREATE TABLE page_revisions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id      BIGINT UNSIGNED NOT NULL,
  lang         VARCHAR(5)      NOT NULL DEFAULT 'en',
  author_id    BIGINT UNSIGNED NULL,
  title        VARCHAR(200)    NOT NULL,
  content_md   LONGTEXT        NOT NULL,
  content_html LONGTEXT        NOT NULL,
  summary      VARCHAR(255)    NOT NULL DEFAULT '',
  -- 'machine' revisions are produced by Core\Translator and are shown to
  -- readers with a badge; a human editing one saves it back as 'human'
  source       ENUM('human','machine') NOT NULL DEFAULT 'human',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rev_page (page_id, lang, id DESC),
  CONSTRAINT fk_rev_page   FOREIGN KEY (page_id)   REFERENCES pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- page_locales  (one row per page per language that exists)
-- ---------------------------------------------------------------------------
-- The pointer to "the current content of page X in language Y". A page has a
-- row here for the default language and one for every translation that has been
-- written; a language with no row simply does not exist for that page, and the
-- reader falls back to the default language with a notice.
--
-- `translated_from_revision_id` is what makes staleness detectable: it records
-- WHICH revision of the source language this translation was made from. When
-- the source moves on, the translation is out of date and can say so, instead
-- of quietly presenting old information as current — which is the failure mode
-- that makes multilingual docs actively worse than monolingual ones.
CREATE TABLE page_locales (
  page_id                     BIGINT UNSIGNED NOT NULL,
  lang                        VARCHAR(5)      NOT NULL,
  title                       VARCHAR(200)    NOT NULL,
  status                      ENUM('draft','published') NOT NULL DEFAULT 'draft',
  source                      ENUM('human','machine')   NOT NULL DEFAULT 'human',
  current_revision_id         BIGINT UNSIGNED NULL,
  translated_from_revision_id BIGINT UNSIGNED NULL,
  updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (page_id, lang),
  KEY ix_locale_lang (lang),
  CONSTRAINT fk_locale_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_locale_rev  FOREIGN KEY (current_revision_id)
    REFERENCES page_revisions (id) ON DELETE SET NULL,
  CONSTRAINT fk_locale_src  FOREIGN KEY (translated_from_revision_id)
    REFERENCES page_revisions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- page_search  (one row per page, kept in step by sp_revision_create)
-- ---------------------------------------------------------------------------
-- Separate from `pages` so the FULLTEXT index is rebuilt only when text
-- actually changes — a reorder or a visibility flip does not touch it.
-- body_text is the Markdown stripped of syntax, so a search for "install" is
-- not outranked by a page that merely links to one.
-- TWO tables, because MySQL allows one parser per FULLTEXT index and these
-- languages need different ones.
--
--   page_search      default parser — splits on whitespace and punctuation.
--                    Correct for languages that put spaces between words:
--                    English, Vietnamese, Indonesian, Korean.
--
--   page_search_cjk  ngram parser — splits into overlapping 2-character
--                    tokens. Required for Thai, Japanese and Chinese, which do
--                    NOT put spaces between words: under the default parser an
--                    entire Thai sentence is one token, so the only query that
--                    could ever match it is that exact sentence. This is not a
--                    tuning detail — it is the difference between search
--                    working and returning nothing at all.
--
-- The cost of the split is that a query has to know which table to look in.
-- sp_search does that from the language code; Core\I18n::usesNgram() is the
-- same list on the PHP side, and the two must agree.
CREATE TABLE page_search (
  page_id   BIGINT UNSIGNED NOT NULL,
  lang      VARCHAR(5)      NOT NULL,
  space_id  BIGINT UNSIGNED NOT NULL,
  title     VARCHAR(200)    NOT NULL,
  body_text MEDIUMTEXT      NOT NULL,
  PRIMARY KEY (page_id, lang),
  KEY ix_search_space (space_id, lang),
  FULLTEXT KEY ft_search (title, body_text),
  CONSTRAINT fk_search_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE page_search_cjk (
  page_id   BIGINT UNSIGNED NOT NULL,
  lang      VARCHAR(5)      NOT NULL,
  space_id  BIGINT UNSIGNED NOT NULL,
  title     VARCHAR(200)    NOT NULL,
  body_text MEDIUMTEXT      NOT NULL,
  PRIMARY KEY (page_id, lang),
  KEY ix_search_cjk_space (space_id, lang),
  FULLTEXT KEY ft_search_cjk (title, body_text) WITH PARSER ngram,
  CONSTRAINT fk_search_cjk_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- redirects
-- ---------------------------------------------------------------------------
-- Every rename or move records the OLD path here. Without this, reorganising a
-- book silently breaks every external link into it — the one mistake that is
-- impossible to undo after the fact, because you cannot know what was linked.
CREATE TABLE redirects (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id   BIGINT UNSIGNED NOT NULL,
  from_path  VARCHAR(700)    NOT NULL,
  to_page_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redirect (space_id, from_path),
  CONSTRAINT fk_redirect_space FOREIGN KEY (space_id)   REFERENCES spaces (id) ON DELETE CASCADE,
  CONSTRAINT fk_redirect_page  FOREIGN KEY (to_page_id) REFERENCES pages  (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- assets
-- ---------------------------------------------------------------------------
-- Files live in storage/uploads (OUTSIDE the webroot) under their sha256 and
-- are served by AssetController, which checks the owning space's visibility
-- first. Dedup on sha256 means pasting the same screenshot into ten pages
-- stores one file.
CREATE TABLE assets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id    BIGINT UNSIGNED NOT NULL,
  sha256      CHAR(64)        NOT NULL,
  filename    VARCHAR(255)    NOT NULL,
  mime        VARCHAR(100)    NOT NULL,
  size_bytes  INT UNSIGNED    NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asset (space_id, sha256),
  KEY ix_asset_sha (sha256),
  CONSTRAINT fk_asset_space FOREIGN KEY (space_id)    REFERENCES spaces (id) ON DELETE CASCADE,
  CONSTRAINT fk_asset_user  FOREIGN KEY (uploaded_by) REFERENCES users  (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- login_attempts  (brute-force throttling)
-- ---------------------------------------------------------------------------
-- Every sign-in attempt, successful or not. Two indexes because the throttle
-- asks two different questions: "is this ADDRESS being hammered?" (which
-- catches one attacker working through a password list) and "is this ACCOUNT
-- being hammered?" (which catches a botnet spread across many addresses, where
-- no single IP looks unusual).
--
-- `ip` is VARBINARY(16) holding the packed address from inet_pton, so IPv6 fits
-- and the index stays compact.
CREATE TABLE login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARBINARY(16)   NOT NULL,
  email      VARCHAR(190)    NOT NULL,
  succeeded  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_attempt_ip    (ip, created_at),
  KEY ix_attempt_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- rate_limits
-- ---------------------------------------------------------------------------
-- A fixed-window counter per (bucket, address). Deliberately not per session:
-- an anonymous attacker simply drops the cookie, so the address is the only
-- thing worth counting for unauthenticated endpoints.
--
-- Fixed window rather than sliding: it can let through up to 2x the limit
-- across a window boundary, which is fine for protecting a FULLTEXT query and
-- costs one row instead of one row per request.
CREATE TABLE rate_limits (
  bucket       VARCHAR(48)   NOT NULL,
  ip           VARBINARY(16) NOT NULL,
  window_start DATETIME      NOT NULL,
  hits         INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, ip),
  KEY ix_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- audit_log
-- ---------------------------------------------------------------------------
-- Who did what that cannot be reconstructed from page_revisions: sign-ins,
-- deletions, role and visibility changes. Revisions already record page edits,
-- so those are deliberately not duplicated here.
--
-- user_id is ON DELETE SET NULL rather than CASCADE: deleting an account must
-- not erase the record of what it did.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  actor       VARCHAR(120)    NOT NULL DEFAULT '',
  action      VARCHAR(60)     NOT NULL,
  target_type VARCHAR(40)     NOT NULL DEFAULT '',
  target_id   BIGINT UNSIGNED NULL,
  detail      VARCHAR(500)    NOT NULL DEFAULT '',
  ip          VARBINARY(16)   NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_time (created_at),
  KEY ix_audit_user (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- sessions
-- ---------------------------------------------------------------------------
-- Session storage moved out of PHP's file handler so more than one web server
-- can serve the same site. On one box the file handler is fine; the moment
-- there are two, a reader signs in on one and is anonymous on the other.
--
-- `data` is BLOB, not TEXT: PHP's serialized session format is bytes, and a
-- character column would let MySQL attempt a charset conversion on it.
CREATE TABLE sessions (
  id         VARCHAR(128)    NOT NULL,
  data       BLOB            NOT NULL,
  user_id    BIGINT UNSIGNED NULL,
  ip         VARBINARY(16)   NULL,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- the GC sweep is a range scan over this
  KEY ix_sessions_updated (updated_at),
  KEY ix_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------
-- settings  (site-wide key/value)
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
  k VARCHAR(80)  NOT NULL,
  v VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO settings (k, v) VALUES
  ('site_title',   'Organice'),
  ('site_tagline', 'Documentation'),
  ('home_space',   ''),
  /* 'multi'  — many books, listed on the home page, pages at /s/<space>/<path>
     'single' — ONE book IS the site. The space list disappears and pages live
                at /<path>, with the old /s/<space>/<path> URLs 301-ing across
                so nothing that was ever shared breaks. `single_space` names it. */
  ('site_mode',    'multi'),
  ('single_space', ''),
  -- ---- white-labelling ----
  -- Brand images are stored OUTSIDE the webroot and served by BrandController;
  -- these hold the stored filename, not a URL.
  ('brand_logo',      ''),
  ('brand_logo_dark', ''),
  ('brand_favicon',   ''),
  -- '' keeps the built-in accent
  ('brand_accent',    ''),
  ('brand_footer',    ''),
  -- The default language is served WITHOUT a URL prefix; every other language
  -- gets one (/th/...). That keeps every existing link working and means a
  -- monolingual site never sees a locale segment at all.
  ('default_lang', 'en'),
  ('languages',    'en,th,ko,ja,vi,id,zh'),
  -- '' disables machine translation entirely. See Core\Translator.
  ('mt_driver',    '');
