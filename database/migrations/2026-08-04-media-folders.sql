-- Media library folders.
--
-- For databases created before the media library existed. A fresh install gets
-- this from schema.sql and does not need to run it.
--
--   mysql -u root -p -D organice < database/migrations/2026-08-04-media-folders.sql
--   mysql -u root -p -D organice < database/procedures.sql
--
-- Re-running procedures.sql afterwards is REQUIRED, not optional: the new
-- sp_asset_* procedures select the column added here.
--
-- Safe to run twice. MySQL has no `ADD COLUMN IF NOT EXISTS`, so each statement
-- is guarded by a check against information_schema rather than simply failing
-- the second time.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'SELECT ''assets.folder already present'' AS note',
    'ALTER TABLE assets ADD COLUMN folder VARCHAR(255) NOT NULL DEFAULT '''' AFTER uploaded_by')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'folder'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'SELECT ''ix_asset_folder already present'' AS note',
    'ALTER TABLE assets ADD KEY ix_asset_folder (space_id, folder, id)')
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'assets' AND INDEX_NAME = 'ix_asset_folder'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
