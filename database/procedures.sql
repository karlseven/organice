-- organice — stored procedures
-- ALL database access goes through these. Controllers never write SQL; see
-- app/Core/DB.php, which refuses any name not matching /^sp_[a-z0-9_]+$/.
--
-- Convention: a user id of 0 means "not signed in", and p_is_admin is passed in
-- rather than re-derived, so the visibility rules below read the same way in
-- every procedure.

SET NAMES utf8mb4;
DELIMITER $$

-- ===========================================================================
-- users
-- ===========================================================================
DROP PROCEDURE IF EXISTS sp_user_by_email $$
CREATE PROCEDURE sp_user_by_email(IN p_email VARCHAR(190))
BEGIN
  SELECT id, email, username, display_name, password_hash, role, is_active
    FROM users WHERE email = p_email LIMIT 1;
END $$

DROP PROCEDURE IF EXISTS sp_user_by_id $$
CREATE PROCEDURE sp_user_by_id(IN p_id BIGINT UNSIGNED)
BEGIN
  SELECT id, email, username, display_name, role, is_active, last_login_at, created_at
    FROM users WHERE id = p_id LIMIT 1;
END $$

DROP PROCEDURE IF EXISTS sp_users_all $$
CREATE PROCEDURE sp_users_all()
BEGIN
  SELECT id, email, username, display_name, role, is_active, last_login_at, created_at
    FROM users ORDER BY display_name;
END $$

DROP PROCEDURE IF EXISTS sp_user_create $$
CREATE PROCEDURE sp_user_create(
  IN p_email VARCHAR(190), IN p_username VARCHAR(60), IN p_display VARCHAR(120),
  IN p_hash VARCHAR(255), IN p_role VARCHAR(10))
BEGIN
  INSERT INTO users (email, username, display_name, password_hash, role)
  VALUES (p_email, p_username, p_display, p_hash, p_role);
  SELECT LAST_INSERT_ID() AS id;
END $$

DROP PROCEDURE IF EXISTS sp_user_update $$
CREATE PROCEDURE sp_user_update(
  IN p_id BIGINT UNSIGNED, IN p_display VARCHAR(120), IN p_role VARCHAR(10),
  IN p_active TINYINT)
BEGIN
  UPDATE users SET display_name = p_display, role = p_role, is_active = p_active
   WHERE id = p_id;
END $$

-- Change the sign-in address. Separate from sp_user_update because it is a
-- CREDENTIAL change, not a profile edit: it changes what the account is
-- identified by, and callers should have to ask for it explicitly.
DROP PROCEDURE IF EXISTS sp_user_set_email $$
CREATE PROCEDURE sp_user_set_email(IN p_id BIGINT UNSIGNED, IN p_email VARCHAR(190))
BEGIN
  UPDATE users SET email = p_email WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_user_set_password $$
CREATE PROCEDURE sp_user_set_password(IN p_id BIGINT UNSIGNED, IN p_hash VARCHAR(255))
BEGIN
  UPDATE users SET password_hash = p_hash WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_user_touch_login $$
CREATE PROCEDURE sp_user_touch_login(IN p_id BIGINT UNSIGNED)
BEGIN
  UPDATE users SET last_login_at = NOW() WHERE id = p_id;
END $$

-- ===========================================================================
-- spaces
-- ===========================================================================

-- Every space the given user may READ, with their effective role.
-- The visibility ladder is written out once here and mirrored in sp_space_get
-- and sp_search; if it changes, change all three.
DROP PROCEDURE IF EXISTS sp_spaces_visible $$
CREATE PROCEDURE sp_spaces_visible(IN p_user BIGINT UNSIGNED, IN p_is_admin TINYINT)
BEGIN
  SELECT s.id, s.slug, s.title, s.description, s.visibility, s.accent, s.position,
         COALESCE(m.role, IF(p_is_admin = 1, 'owner', '')) AS member_role,
         (SELECT COUNT(*) FROM pages pg
           WHERE pg.space_id = s.id AND pg.status = 'published') AS page_count
    FROM spaces s
    LEFT JOIN space_members m ON m.space_id = s.id AND m.user_id = p_user
   WHERE p_is_admin = 1
      OR s.visibility = 'public'
      OR (s.visibility = 'internal' AND p_user > 0)
      OR m.user_id IS NOT NULL
   ORDER BY s.position, s.title;
END $$

DROP PROCEDURE IF EXISTS sp_space_by_slug $$
CREATE PROCEDURE sp_space_by_slug(IN p_slug VARCHAR(80), IN p_user BIGINT UNSIGNED)
BEGIN
  SELECT s.*, COALESCE(m.role, '') AS member_role
    FROM spaces s
    LEFT JOIN space_members m ON m.space_id = s.id AND m.user_id = p_user
   WHERE s.slug = p_slug LIMIT 1;
END $$

DROP PROCEDURE IF EXISTS sp_space_by_id $$
CREATE PROCEDURE sp_space_by_id(IN p_id BIGINT UNSIGNED, IN p_user BIGINT UNSIGNED)
BEGIN
  SELECT s.*, COALESCE(m.role, '') AS member_role
    FROM spaces s
    LEFT JOIN space_members m ON m.space_id = s.id AND m.user_id = p_user
   WHERE s.id = p_id LIMIT 1;
END $$

DROP PROCEDURE IF EXISTS sp_space_create $$
CREATE PROCEDURE sp_space_create(
  IN p_slug VARCHAR(80), IN p_title VARCHAR(160), IN p_desc VARCHAR(400),
  IN p_vis VARCHAR(10), IN p_accent CHAR(7), IN p_by BIGINT UNSIGNED)
BEGIN
  INSERT INTO spaces (slug, title, description, visibility, accent, created_by, position)
  VALUES (p_slug, p_title, p_desc, p_vis, p_accent, NULLIF(p_by, 0),
          (SELECT COALESCE(MAX(x.position), 0) + 1 FROM (SELECT position FROM spaces) x));
  SET @new_id = LAST_INSERT_ID();
  -- the creator is always a member, so a private space is not instantly
  -- unreachable by the person who just made it
  INSERT IGNORE INTO space_members (space_id, user_id, role)
    SELECT @new_id, p_by, 'owner' WHERE p_by > 0;
  SELECT @new_id AS id;
END $$

DROP PROCEDURE IF EXISTS sp_space_update $$
CREATE PROCEDURE sp_space_update(
  IN p_id BIGINT UNSIGNED, IN p_title VARCHAR(160), IN p_desc VARCHAR(400),
  IN p_vis VARCHAR(10), IN p_accent CHAR(7))
BEGIN
  UPDATE spaces SET title = p_title, description = p_desc,
                    visibility = p_vis, accent = p_accent
   WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_space_delete $$
CREATE PROCEDURE sp_space_delete(IN p_id BIGINT UNSIGNED)
BEGIN
  DELETE FROM spaces WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_space_members $$
CREATE PROCEDURE sp_space_members(IN p_space BIGINT UNSIGNED)
BEGIN
  SELECT m.user_id, m.role, u.display_name, u.email
    FROM space_members m JOIN users u ON u.id = m.user_id
   WHERE m.space_id = p_space ORDER BY u.display_name;
END $$

DROP PROCEDURE IF EXISTS sp_space_member_set $$
CREATE PROCEDURE sp_space_member_set(
  IN p_space BIGINT UNSIGNED, IN p_user BIGINT UNSIGNED, IN p_role VARCHAR(10))
BEGIN
  IF p_role = '' THEN
    DELETE FROM space_members WHERE space_id = p_space AND user_id = p_user;
  ELSE
    INSERT INTO space_members (space_id, user_id, role) VALUES (p_space, p_user, p_role)
      ON DUPLICATE KEY UPDATE role = VALUES(role);
  END IF;
END $$

-- ===========================================================================
-- pages
-- ===========================================================================

-- The whole navigation tree of a space in one query, already in sidebar order.
-- Ordering by `path` puts every child directly after its parent, so the view
-- can render the tree in a single pass without recursion — the sort key is
-- built from position so siblings keep their manual order.
-- The sidebar in one query. Titles come from the requested language where a
-- translation exists and fall back to the page's default-language title
-- otherwise, so a partially translated book still shows a complete tree rather
-- than a list with holes in it.
DROP PROCEDURE IF EXISTS sp_page_tree $$
CREATE PROCEDURE sp_page_tree(
  IN p_space BIGINT UNSIGNED, IN p_include_drafts TINYINT, IN p_lang VARCHAR(5))
BEGIN
  SELECT p.id, p.space_id, p.parent_id, p.slug, p.path, p.depth, p.position, p.status, p.icon,
         COALESCE(NULLIF(l.title, ''), p.title) AS title,
         (l.page_id IS NOT NULL)                AS translated,
         COALESCE(l.status, '')                 AS locale_status
    FROM pages p
    LEFT JOIN page_locales l ON l.page_id = p.id AND l.lang = p_lang
   WHERE p.space_id = p_space
     AND (p_include_drafts = 1 OR p.status = 'published')
   ORDER BY p.depth, p.parent_id, p.position, p.title;
END $$

-- Resolve a URL to content in one language.
--
-- Returns the requested language when it exists AND is published, otherwise the
-- default language, flagging which happened so the page can say so. The
-- fallback is deliberate: showing the English page with a notice is far more
-- useful to a reader than a 404 telling them the page does not exist, when it
-- plainly does.
DROP PROCEDURE IF EXISTS sp_page_by_path $$
CREATE PROCEDURE sp_page_by_path(
  IN p_space BIGINT UNSIGNED, IN p_path VARCHAR(700),
  IN p_lang VARCHAR(5), IN p_default VARCHAR(5), IN p_drafts TINYINT)
BEGIN
  DECLARE v_page BIGINT UNSIGNED;
  DECLARE v_use  VARCHAR(5);

  SELECT id INTO v_page FROM pages
   WHERE space_id = p_space AND path = p_path LIMIT 1;

  IF v_page IS NULL THEN
    SELECT NULL AS id WHERE FALSE;   -- empty result set; the caller 404s
  ELSE
    SELECT lang INTO v_use FROM page_locales
     WHERE page_id = v_page AND lang = p_lang
       AND (p_drafts = 1 OR status = 'published')
     LIMIT 1;

    IF v_use IS NULL THEN SET v_use = p_default; END IF;

    SELECT p.*, l.lang AS content_lang, l.source, l.title AS locale_title,
           l.status AS locale_status, l.translated_from_revision_id,
           r.content_html, r.content_md, r.created_at AS revised_at,
           r.id AS revision_id,
           u.display_name AS revised_by,
           (v_use <> p_lang) AS is_fallback,
           /* Stale when the source language has moved on since this
              translation was taken from it. Compared by revision id, which is
              monotonic, rather than by timestamp — two saves in the same second
              are otherwise indistinguishable. */
           (l.translated_from_revision_id IS NOT NULL
             AND l.translated_from_revision_id <
                 COALESCE((SELECT dl.current_revision_id FROM page_locales dl
                            WHERE dl.page_id = p.id AND dl.lang = p_default), 0)
           ) AS is_stale
      FROM pages p
      LEFT JOIN page_locales   l ON l.page_id = p.id AND l.lang = v_use
      LEFT JOIN page_revisions r ON r.id = l.current_revision_id
      LEFT JOIN users u ON u.id = r.author_id
     WHERE p.id = v_page LIMIT 1;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_page_by_id $$
CREATE PROCEDURE sp_page_by_id(IN p_id BIGINT UNSIGNED, IN p_lang VARCHAR(5))
BEGIN
  SELECT p.*, s.slug AS space_slug, s.title AS space_title,
         l.lang AS content_lang, l.source, l.status AS locale_status,
         l.current_revision_id, l.translated_from_revision_id,
         COALESCE(NULLIF(l.title, ''), p.title) AS locale_title,
         r.content_html, r.content_md
    FROM pages p
    JOIN spaces s ON s.id = p.space_id
    LEFT JOIN page_locales   l ON l.page_id = p.id AND l.lang = p_lang
    LEFT JOIN page_revisions r ON r.id = l.current_revision_id
   WHERE p.id = p_id LIMIT 1;
END $$

-- Every language this page has been written in, for the editor's picker and the
-- reader's language switcher.
DROP PROCEDURE IF EXISTS sp_page_locales $$
CREATE PROCEDURE sp_page_locales(IN p_page BIGINT UNSIGNED)
BEGIN
  SELECT lang, title, status, source, current_revision_id,
         translated_from_revision_id, updated_at
    FROM page_locales WHERE page_id = p_page ORDER BY lang;
END $$

DROP PROCEDURE IF EXISTS sp_locale_status $$
CREATE PROCEDURE sp_locale_status(
  IN p_page BIGINT UNSIGNED, IN p_lang VARCHAR(5), IN p_status VARCHAR(12))
BEGIN
  UPDATE page_locales SET status = p_status WHERE page_id = p_page AND lang = p_lang;
END $$

DROP PROCEDURE IF EXISTS sp_locale_delete $$
CREATE PROCEDURE sp_locale_delete(IN p_page BIGINT UNSIGNED, IN p_lang VARCHAR(5))
BEGIN
  DELETE FROM page_locales WHERE page_id = p_page AND lang = p_lang;
  DELETE FROM page_search     WHERE page_id = p_page AND lang = p_lang;
  DELETE FROM page_search_cjk WHERE page_id = p_page AND lang = p_lang;
END $$

-- First published page of a space, in sidebar order — the space's landing page.
DROP PROCEDURE IF EXISTS sp_page_first $$
CREATE PROCEDURE sp_page_first(IN p_space BIGINT UNSIGNED, IN p_include_drafts TINYINT)
BEGIN
  SELECT id, path, title FROM pages
   WHERE space_id = p_space AND (p_include_drafts = 1 OR status = 'published')
   ORDER BY depth, position, title LIMIT 1;
END $$

DROP PROCEDURE IF EXISTS sp_page_create $$
CREATE PROCEDURE sp_page_create(
  IN p_space BIGINT UNSIGNED, IN p_parent BIGINT UNSIGNED, IN p_slug VARCHAR(120),
  IN p_title VARCHAR(200), IN p_by BIGINT UNSIGNED, IN p_lang VARCHAR(5))
BEGIN
  DECLARE v_path VARCHAR(700);
  DECLARE v_depth TINYINT UNSIGNED DEFAULT 0;
  DECLARE v_pos INT DEFAULT 0;

  IF p_parent > 0 THEN
    SELECT CONCAT(path, '/', p_slug), depth + 1 INTO v_path, v_depth
      FROM pages WHERE id = p_parent;
  ELSE
    SET v_path = p_slug;
  END IF;

  SELECT COALESCE(MAX(position), 0) + 1 INTO v_pos FROM pages
   WHERE space_id = p_space
     AND ((p_parent > 0 AND parent_id = p_parent) OR (p_parent = 0 AND parent_id IS NULL));

  INSERT INTO pages (space_id, parent_id, slug, title, path, depth, position, created_by)
  VALUES (p_space, NULLIF(p_parent, 0), p_slug, p_title, v_path, v_depth, v_pos, NULLIF(p_by, 0));
  SET @pid = LAST_INSERT_ID();

  -- the page exists in its authoring language from the moment it is created,
  -- so the editor has a locale row to save into
  INSERT INTO page_locales (page_id, lang, title) VALUES (@pid, p_lang, p_title);

  SELECT @pid AS id, v_path AS path;
END $$

-- Rename/retitle. The old path is kept as a redirect so existing links survive.
DROP PROCEDURE IF EXISTS sp_page_rename $$
CREATE PROCEDURE sp_page_rename(
  IN p_id BIGINT UNSIGNED, IN p_slug VARCHAR(120), IN p_title VARCHAR(200),
  IN p_status VARCHAR(12), IN p_lang VARCHAR(5), IN p_default VARCHAR(5))
BEGIN
  DECLARE v_space BIGINT UNSIGNED;
  DECLARE v_old VARCHAR(700);
  SELECT space_id, path INTO v_space, v_old FROM pages WHERE id = p_id;

  /* The slug is shared by every language, so ANY language may rename it — but
     the default-language title is the tree's fallback and must only change when
     the default language itself is edited. */
  UPDATE pages
     SET slug = p_slug, status = p_status,
         title = IF(p_lang = p_default, p_title, title)
   WHERE id = p_id;

  UPDATE page_locales SET title = p_title WHERE page_id = p_id AND lang = p_lang;
  CALL sp_page_paths_rebuild(v_space);

  INSERT IGNORE INTO redirects (space_id, from_path, to_page_id)
    SELECT v_space, v_old, p_id FROM pages WHERE id = p_id AND path <> v_old;

  SELECT path FROM pages WHERE id = p_id;
END $$

-- Move a page and place it precisely among its new siblings.
--
-- The placement is expressed as an INTENT — 'first', 'last', or 'after' a named
-- sibling — not as a raw position integer. A raw integer is ambiguous the
-- moment two siblings share one: "position 3" cannot say whether it means
-- before or after the page already sitting there, and drag-and-drop produces
-- exactly that collision on every drop. Saying "after page 12" cannot be
-- misread.
--
-- Siblings are then renumbered 1..n, so positions never drift, never collide,
-- and never need a migration to tidy up.
-- The icon is deliberately NOT part of sp_page_rename: changing it does not
-- change the path, so it must not go through the machinery that rebuilds paths
-- and records a redirect.
DROP PROCEDURE IF EXISTS sp_page_set_icon $$
/* p_icon must be at least as wide as pages.icon. A narrower parameter does not
   error — MySQL truncates it on the way in, so 'lucide:square-centerline-...'
   would arrive as a shorter name that is a DIFFERENT valid icon. */
CREATE PROCEDURE sp_page_set_icon(IN p_id BIGINT UNSIGNED, IN p_icon VARCHAR(64))
BEGIN
  UPDATE pages SET icon = p_icon WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_page_move $$
CREATE PROCEDURE sp_page_move(
  IN p_id BIGINT UNSIGNED, IN p_parent BIGINT UNSIGNED,
  IN p_mode VARCHAR(8), IN p_after BIGINT UNSIGNED)
BEGIN
  DECLARE v_space BIGINT UNSIGNED;
  DECLARE v_old VARCHAR(700);
  DECLARE v_slot INT DEFAULT 0;

  SELECT space_id, path INTO v_space, v_old FROM pages WHERE id = p_id;

  IF p_mode = 'first' THEN
    SET v_slot = 0;
  ELSEIF p_mode = 'after' THEN
    -- half a step past the anchor: lands between it and whatever follows,
    -- whichever way the renumber below rounds
    SELECT COALESCE(position, 0) INTO v_slot FROM pages WHERE id = p_after;
  ELSE
    SELECT COALESCE(MAX(position), 0) INTO v_slot FROM pages
     WHERE space_id = v_space
       AND ((p_parent > 0 AND parent_id = p_parent) OR (p_parent = 0 AND parent_id IS NULL))
       AND id <> p_id;
  END IF;

  UPDATE pages
     SET parent_id = NULLIF(p_parent, 0),
         -- x2 +1 so the moved page slots strictly between 2*slot and 2*(slot+1)
         position  = v_slot * 2 + 1
   WHERE id = p_id;

  UPDATE pages
     SET position = position * 2
   WHERE space_id = v_space
     AND ((p_parent > 0 AND parent_id = p_parent) OR (p_parent = 0 AND parent_id IS NULL))
     AND id <> p_id;

  -- collapse back to a clean 1..n
  UPDATE pages tgt
    JOIN (
      SELECT id, ROW_NUMBER() OVER (ORDER BY position, title, id) AS rn
        FROM pages
       WHERE space_id = v_space
         AND ((p_parent > 0 AND parent_id = p_parent) OR (p_parent = 0 AND parent_id IS NULL))
    ) ord ON ord.id = tgt.id
     SET tgt.position = ord.rn;

  CALL sp_page_paths_rebuild(v_space);

  INSERT IGNORE INTO redirects (space_id, from_path, to_page_id)
    SELECT v_space, v_old, p_id FROM pages WHERE id = p_id AND path <> v_old;

  SELECT path FROM pages WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_page_delete $$
CREATE PROCEDURE sp_page_delete(IN p_id BIGINT UNSIGNED)
BEGIN
  -- ON DELETE CASCADE on pages.parent_id takes the whole subtree
  DELETE FROM pages WHERE id = p_id;
END $$

-- Recompute `path` and `depth` for a whole space, level by level.
--
-- Deliberately iterative rather than a recursive CTE: MySQL will not let an
-- UPDATE target a table that a CTE in the same statement reads from, and the
-- level-by-level join has the useful property that no row is both a source and
-- a target within one statement (parents are always one level shallower than
-- the children being written).
--
-- Caveat: the unique key on (space_id, path) is enforced per statement, so
-- swapping two sibling slugs in a single call would collide mid-rebuild.
-- Editor\Slug guards against that by refusing a slug already taken by a
-- sibling before any of this runs.
DROP PROCEDURE IF EXISTS sp_page_paths_rebuild $$
CREATE PROCEDURE sp_page_paths_rebuild(IN p_space BIGINT UNSIGNED)
BEGIN
  DECLARE v_lvl INT DEFAULT 0;
  DECLARE v_n INT DEFAULT 1;

  UPDATE pages SET path = slug, depth = 0
   WHERE space_id = p_space AND parent_id IS NULL;

  WHILE v_n > 0 AND v_lvl < 32 DO
    UPDATE pages c
      JOIN pages p ON p.id = c.parent_id
       SET c.path = CONCAT(p.path, '/', c.slug), c.depth = v_lvl + 1
     WHERE c.space_id = p_space AND p.space_id = p_space AND p.depth = v_lvl;
    SET v_n = ROW_COUNT();
    SET v_lvl = v_lvl + 1;
  END WHILE;
END $$

-- ===========================================================================
-- revisions
-- ===========================================================================

-- Save. Appends a revision, points the page at it, and refreshes the search
-- row — one call so those three can never drift apart.
DROP PROCEDURE IF EXISTS sp_revision_create $$
CREATE PROCEDURE sp_revision_create(
  IN p_page BIGINT UNSIGNED, IN p_lang VARCHAR(5), IN p_author BIGINT UNSIGNED,
  IN p_title VARCHAR(200), IN p_md LONGTEXT, IN p_html LONGTEXT,
  IN p_text MEDIUMTEXT, IN p_summary VARCHAR(255),
  IN p_source VARCHAR(8), IN p_from_rev BIGINT UNSIGNED, IN p_default VARCHAR(5))
BEGIN
  DECLARE v_space BIGINT UNSIGNED;
  DECLARE v_ngram TINYINT DEFAULT 0;

  SELECT space_id INTO v_space FROM pages WHERE id = p_page;

  /* Which FULLTEXT table this language belongs in. The same list exists in
     Core\I18n::usesNgram() — they must agree, or content is indexed in one
     table and searched for in the other, which fails silently by simply
     never matching. */
  SET v_ngram = (p_lang IN ('th', 'ja', 'zh'));

  INSERT INTO page_revisions
    (page_id, lang, author_id, title, content_md, content_html, summary, source)
  VALUES
    (p_page, p_lang, NULLIF(p_author, 0), p_title, p_md, p_html, p_summary, p_source);
  SET @rev = LAST_INSERT_ID();

  INSERT INTO page_locales
    (page_id, lang, title, current_revision_id, translated_from_revision_id, source)
  VALUES (p_page, p_lang, p_title, @rev, NULLIF(p_from_rev, 0), p_source)
    ON DUPLICATE KEY UPDATE
      title = VALUES(title),
      current_revision_id = VALUES(current_revision_id),
      source = VALUES(source),
      /* Only advance the source pointer when the caller supplied one. A human
         editing a translation directly passes 0, and must not silently mark it
         as up to date with a source revision they never read. */
      translated_from_revision_id =
        COALESCE(VALUES(translated_from_revision_id), translated_from_revision_id);

  -- the default language's title is mirrored onto the page for the tree,
  -- the admin screens, and as every other language's fallback
  IF p_lang = p_default THEN
    UPDATE pages SET title = p_title WHERE id = p_page;
  END IF;

  IF v_ngram = 1 THEN
    INSERT INTO page_search_cjk (page_id, lang, space_id, title, body_text)
    VALUES (p_page, p_lang, v_space, p_title, p_text)
      ON DUPLICATE KEY UPDATE title = VALUES(title), body_text = VALUES(body_text);
  ELSE
    INSERT INTO page_search (page_id, lang, space_id, title, body_text)
    VALUES (p_page, p_lang, v_space, p_title, p_text)
      ON DUPLICATE KEY UPDATE title = VALUES(title), body_text = VALUES(body_text);
  END IF;

  SELECT @rev AS id;
END $$

-- Rewrite a revision's cached HTML in place, without creating a new revision.
--
-- Only for scripts/rerender.php, after the renderer itself changes. Deliberately
-- does NOT touch content_md, the author, or the timestamp: the writing did not
-- change, only our rendering of it, and showing every reader that the page was
-- "updated just now" by whoever ran a maintenance script would be a lie.
DROP PROCEDURE IF EXISTS sp_revision_rerender $$
CREATE PROCEDURE sp_revision_rerender(
  IN p_rev BIGINT UNSIGNED, IN p_html LONGTEXT, IN p_text MEDIUMTEXT)
BEGIN
  UPDATE page_revisions SET content_html = p_html WHERE id = p_rev;

  -- the revision knows its own language, so both search tables can be updated
  -- unconditionally; only the one holding this language has a matching row
  UPDATE page_search ps
    JOIN page_revisions r ON r.page_id = ps.page_id AND r.lang = ps.lang
     SET ps.body_text = p_text
   WHERE r.id = p_rev;

  UPDATE page_search_cjk ps
    JOIN page_revisions r ON r.page_id = ps.page_id AND r.lang = ps.lang
     SET ps.body_text = p_text
   WHERE r.id = p_rev;
END $$

-- History is per language: editing the Thai page must not show up as history of
-- the English one.
DROP PROCEDURE IF EXISTS sp_revisions $$
CREATE PROCEDURE sp_revisions(IN p_page BIGINT UNSIGNED, IN p_lang VARCHAR(5), IN p_limit INT)
BEGIN
  SELECT r.id, r.title, r.summary, r.created_at, r.source,
         COALESCE(u.display_name, 'deleted user') AS author,
         (r.id = l.current_revision_id) AS is_current,
         CHAR_LENGTH(r.content_md) AS size_chars
    FROM page_revisions r
    LEFT JOIN page_locales l ON l.page_id = r.page_id AND l.lang = r.lang
    LEFT JOIN users u ON u.id = r.author_id
   WHERE r.page_id = p_page AND r.lang = p_lang
   ORDER BY r.id DESC
   LIMIT p_limit;
END $$

DROP PROCEDURE IF EXISTS sp_revision_by_id $$
CREATE PROCEDURE sp_revision_by_id(IN p_id BIGINT UNSIGNED)
BEGIN
  SELECT r.*, p.space_id FROM page_revisions r
    JOIN pages p ON p.id = r.page_id
   WHERE r.id = p_id LIMIT 1;
END $$

-- ===========================================================================
-- search
-- ===========================================================================
-- MySQL FULLTEXT in boolean mode. Good to roughly the tens of thousands of
-- pages; the swap-in point for a dedicated engine is documented in docs/PLAN.md.
-- Drafts are never searchable — a half-written page surfacing in results is
-- worse than it being hard to find.
-- Search within one language.
--
-- The two branches are identical apart from the table, and that duplication is
-- on purpose: MySQL will not let a FULLTEXT index be chosen at runtime, so the
-- alternative is dynamic SQL, which would give up the prepared-statement
-- protection that the whole procedures-only design exists to guarantee.
--
-- Falls back to the default language when the requested one finds nothing, so a
-- reader browsing in Thai still gets results from a book that only exists in
-- English rather than an empty page.
DROP PROCEDURE IF EXISTS sp_search $$
CREATE PROCEDURE sp_search(
  IN p_q VARCHAR(255), IN p_space BIGINT UNSIGNED,
  IN p_user BIGINT UNSIGNED, IN p_is_admin TINYINT, IN p_limit INT,
  IN p_lang VARCHAR(5))
BEGIN
  IF p_lang IN ('th', 'ja', 'zh') THEN
    SELECT pg.id, ps.title, pg.path, ps.lang, s.slug AS space_slug, s.title AS space_title,
           MATCH(ps.title, ps.body_text) AGAINST (p_q IN BOOLEAN MODE) AS score,
           SUBSTRING(ps.body_text, 1, 400) AS excerpt
      FROM page_search_cjk ps
      JOIN pages  pg ON pg.id = ps.page_id
      JOIN spaces s  ON s.id  = ps.space_id
      LEFT JOIN space_members m ON m.space_id = s.id AND m.user_id = p_user
      LEFT JOIN page_locales l ON l.page_id = pg.id AND l.lang = ps.lang
     WHERE MATCH(ps.title, ps.body_text) AGAINST (p_q IN BOOLEAN MODE)
       AND ps.lang = p_lang
       AND pg.status = 'published'
       AND COALESCE(l.status, 'published') = 'published'
       AND (p_space = 0 OR s.id = p_space)
       AND (p_is_admin = 1
         OR s.visibility = 'public'
         OR (s.visibility = 'internal' AND p_user > 0)
         OR m.user_id IS NOT NULL)
     ORDER BY score DESC
     LIMIT p_limit;
  ELSE
    SELECT pg.id, ps.title, pg.path, ps.lang, s.slug AS space_slug, s.title AS space_title,
           MATCH(ps.title, ps.body_text) AGAINST (p_q IN BOOLEAN MODE) AS score,
           SUBSTRING(ps.body_text, 1, 400) AS excerpt
      FROM page_search ps
      JOIN pages  pg ON pg.id = ps.page_id
      JOIN spaces s  ON s.id  = ps.space_id
      LEFT JOIN space_members m ON m.space_id = s.id AND m.user_id = p_user
      LEFT JOIN page_locales l ON l.page_id = pg.id AND l.lang = ps.lang
     WHERE MATCH(ps.title, ps.body_text) AGAINST (p_q IN BOOLEAN MODE)
       AND ps.lang = p_lang
       AND pg.status = 'published'
       AND COALESCE(l.status, 'published') = 'published'
       AND (p_space = 0 OR s.id = p_space)
       AND (p_is_admin = 1
         OR s.visibility = 'public'
         OR (s.visibility = 'internal' AND p_user > 0)
         OR m.user_id IS NOT NULL)
     ORDER BY score DESC
     LIMIT p_limit;
  END IF;
END $$

-- Everything a sitemap needs, in one pass: every published page in every
-- language, restricted to PUBLIC spaces. Internal and private spaces are
-- deliberately absent — a sitemap is a list handed to search engines, and
-- putting a private space's URLs in it defeats the visibility setting even if
-- the pages themselves still refuse to serve.
DROP PROCEDURE IF EXISTS sp_sitemap $$
CREATE PROCEDURE sp_sitemap()
BEGIN
  SELECT s.slug AS space_slug, p.path, l.lang,
         GREATEST(p.updated_at, COALESCE(l.updated_at, p.updated_at)) AS changed_at
    FROM pages p
    JOIN spaces s ON s.id = p.space_id
    JOIN page_locales l ON l.page_id = p.id
   WHERE s.visibility = 'public'
     AND p.status = 'published'
     AND l.status = 'published'
   ORDER BY s.slug, p.depth, p.position, l.lang;
END $$

-- ===========================================================================
-- redirects / assets / settings
-- ===========================================================================
DROP PROCEDURE IF EXISTS sp_redirect_find $$
CREATE PROCEDURE sp_redirect_find(IN p_space BIGINT UNSIGNED, IN p_path VARCHAR(700))
BEGIN
  SELECT r.to_page_id, p.path FROM redirects r
    JOIN pages p ON p.id = r.to_page_id
   WHERE r.space_id = p_space AND r.from_path = p_path LIMIT 1;
END $$

DROP PROCEDURE IF EXISTS sp_asset_create $$
CREATE PROCEDURE sp_asset_create(
  IN p_space BIGINT UNSIGNED, IN p_sha CHAR(64), IN p_name VARCHAR(255),
  IN p_mime VARCHAR(100), IN p_size INT UNSIGNED, IN p_by BIGINT UNSIGNED)
BEGIN
  INSERT INTO assets (space_id, sha256, filename, mime, size_bytes, uploaded_by)
  VALUES (p_space, p_sha, p_name, p_mime, p_size, NULLIF(p_by, 0))
    ON DUPLICATE KEY UPDATE filename = VALUES(filename);
  SELECT id, sha256, filename, mime FROM assets
   WHERE space_id = p_space AND sha256 = p_sha LIMIT 1;
END $$

-- Every sha the database still knows about, with its age. The sweeper compares
-- this against what is on disk and against what any revision references.
DROP PROCEDURE IF EXISTS sp_assets_all $$
CREATE PROCEDURE sp_assets_all()
BEGIN
  SELECT a.id, a.sha256, a.filename, a.size_bytes, a.space_id, a.created_at,
         TIMESTAMPDIFF(DAY, a.created_at, NOW()) AS age_days
    FROM assets a ORDER BY a.id;
END $$

-- Every stored Markdown body, in id order, for the sweeper and the link
-- checker to scan. Paged, because the whole corpus will not fit in memory
-- once a site is real.
DROP PROCEDURE IF EXISTS sp_revision_bodies $$
CREATE PROCEDURE sp_revision_bodies(IN p_after BIGINT UNSIGNED, IN p_limit INT)
BEGIN
  SELECT r.id, r.page_id, r.lang, r.content_md, p.space_id, p.path
    FROM page_revisions r
    JOIN pages p ON p.id = r.page_id
   WHERE r.id > p_after
   ORDER BY r.id
   LIMIT p_limit;
END $$

DROP PROCEDURE IF EXISTS sp_asset_delete $$
CREATE PROCEDURE sp_asset_delete(IN p_id BIGINT UNSIGNED)
BEGIN
  DELETE FROM assets WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_asset_by_sha $$
CREATE PROCEDURE sp_asset_by_sha(IN p_sha CHAR(64))
BEGIN
  SELECT a.*, s.visibility, s.id AS sid FROM assets a
    JOIN spaces s ON s.id = a.space_id
   WHERE a.sha256 = p_sha LIMIT 1;
END $$

-- ===========================================================================
-- login throttling / audit
-- ===========================================================================
DROP PROCEDURE IF EXISTS sp_login_attempt_add $$
CREATE PROCEDURE sp_login_attempt_add(
  IN p_ip VARBINARY(16), IN p_email VARCHAR(190), IN p_ok TINYINT)
BEGIN
  INSERT INTO login_attempts (ip, email, succeeded) VALUES (p_ip, p_email, p_ok);

  /* A successful sign-in clears that account's failures, so someone who
     mistypes four times and then gets it right is not still one attempt from a
     lockout. The IP's own history is deliberately left alone: an attacker
     holding one valid account must not be able to reset the address-level
     throttle at will. */
  IF p_ok = 1 THEN
    DELETE FROM login_attempts
     WHERE email = p_email AND succeeded = 0 AND created_at > NOW() - INTERVAL 1 DAY;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_login_failures $$
CREATE PROCEDURE sp_login_failures(
  IN p_ip VARBINARY(16), IN p_email VARCHAR(190), IN p_minutes INT)
BEGIN
  SELECT
    (SELECT COUNT(*) FROM login_attempts
      WHERE ip = p_ip AND succeeded = 0
        AND created_at > NOW() - INTERVAL p_minutes MINUTE) AS by_ip,
    (SELECT COUNT(*) FROM login_attempts
      WHERE email = p_email AND succeeded = 0
        AND created_at > NOW() - INTERVAL p_minutes MINUTE) AS by_email;
END $$

-- Housekeeping: the throttle only ever looks at a recent window, so anything
-- older is dead weight. Called opportunistically from the login path.
DROP PROCEDURE IF EXISTS sp_login_attempts_prune $$
CREATE PROCEDURE sp_login_attempts_prune()
BEGIN
  DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 7 DAY LIMIT 1000;
END $$

-- Count one hit and return the running total for the current window.
-- The window resets in the same statement that increments, so there is no
-- read-then-write gap for two concurrent requests to slip through.
DROP PROCEDURE IF EXISTS sp_rate_hit $$
CREATE PROCEDURE sp_rate_hit(IN p_bucket VARCHAR(48), IN p_ip VARBINARY(16), IN p_window INT)
BEGIN
  INSERT INTO rate_limits (bucket, ip, window_start, hits)
  VALUES (p_bucket, p_ip, NOW(), 1)
    ON DUPLICATE KEY UPDATE
      hits = IF(window_start < NOW() - INTERVAL p_window SECOND, 1, hits + 1),
      window_start = IF(window_start < NOW() - INTERVAL p_window SECOND, NOW(), window_start);

  SELECT hits FROM rate_limits WHERE bucket = p_bucket AND ip = p_ip;
END $$

DROP PROCEDURE IF EXISTS sp_rate_prune $$
CREATE PROCEDURE sp_rate_prune(IN p_window INT)
BEGIN
  DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL p_window SECOND LIMIT 2000;
END $$

DROP PROCEDURE IF EXISTS sp_audit_add $$
CREATE PROCEDURE sp_audit_add(
  IN p_user BIGINT UNSIGNED, IN p_actor VARCHAR(120), IN p_action VARCHAR(60),
  IN p_type VARCHAR(40), IN p_target BIGINT UNSIGNED, IN p_detail VARCHAR(500),
  IN p_ip VARBINARY(16))
BEGIN
  INSERT INTO audit_log (user_id, actor, action, target_type, target_id, detail, ip)
  VALUES (NULLIF(p_user, 0), p_actor, p_action, p_type, NULLIF(p_target, 0), p_detail, p_ip);
END $$

DROP PROCEDURE IF EXISTS sp_audit_recent $$
CREATE PROCEDURE sp_audit_recent(IN p_limit INT)
BEGIN
  SELECT id, actor, action, target_type, target_id, detail,
         INET6_NTOA(ip) AS ip_text, created_at
    FROM audit_log ORDER BY id DESC LIMIT p_limit;
END $$

-- ===========================================================================
-- sessions
-- ===========================================================================
-- Read WITH a row lock. The lock is taken inside the caller's transaction and
-- held until the session is written, which serialises concurrent requests
-- carrying the same cookie.
--
-- That serialisation is the whole point. The editor fires preview, save and
-- history requests that overlap; without a lock, two of them read the same
-- session, both modify their own copy, and the last write silently discards
-- the other's changes — which is how a CSRF token or a flash message
-- disappears under load. PHP's file handler locks for exactly this reason, and
-- a database handler that skips it is a regression, not a simplification.
DROP PROCEDURE IF EXISTS sp_session_read $$
CREATE PROCEDURE sp_session_read(IN p_id VARCHAR(128), IN p_max_age INT)
BEGIN
  SELECT data FROM sessions
   WHERE id = p_id AND updated_at > NOW() - INTERVAL p_max_age SECOND
   FOR UPDATE;
END $$

DROP PROCEDURE IF EXISTS sp_session_write $$
CREATE PROCEDURE sp_session_write(
  IN p_id VARCHAR(128), IN p_data BLOB, IN p_user BIGINT UNSIGNED, IN p_ip VARBINARY(16))
BEGIN
  INSERT INTO sessions (id, data, user_id, ip) VALUES (p_id, p_data, NULLIF(p_user, 0), p_ip)
    ON DUPLICATE KEY UPDATE
      data = VALUES(data), user_id = VALUES(user_id), ip = VALUES(ip),
      updated_at = NOW();
END $$

DROP PROCEDURE IF EXISTS sp_session_destroy $$
CREATE PROCEDURE sp_session_destroy(IN p_id VARCHAR(128))
BEGIN
  DELETE FROM sessions WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_session_gc $$
CREATE PROCEDURE sp_session_gc(IN p_max_age INT)
BEGIN
  -- bounded so a long-neglected table cannot lock everything in one sweep
  DELETE FROM sessions WHERE updated_at < NOW() - INTERVAL p_max_age SECOND LIMIT 5000;
END $$

-- Sign a user out everywhere. Not wired to a button yet; it is what a password
-- change should call, and it is the reason user_id is stored on the row.
DROP PROCEDURE IF EXISTS sp_session_kill_user $$
CREATE PROCEDURE sp_session_kill_user(IN p_user BIGINT UNSIGNED)
BEGIN
  DELETE FROM sessions WHERE user_id = p_user;
END $$

DROP PROCEDURE IF EXISTS sp_setting_all $$
CREATE PROCEDURE sp_setting_all()
BEGIN
  SELECT k, v FROM settings;
END $$

DROP PROCEDURE IF EXISTS sp_setting_set $$
CREATE PROCEDURE sp_setting_set(IN p_k VARCHAR(80), IN p_v VARCHAR(500))
BEGIN
  INSERT INTO settings (k, v) VALUES (p_k, p_v)
    ON DUPLICATE KEY UPDATE v = VALUES(v);
END $$

DELIMITER ;
