-- Run this once only when upgrading a database created by an earlier version.
-- It preserves the existing JSON state while renaming app_state to tbl_app_state.

SET @has_old_table = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'app_state'
);
SET @has_new_table = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'tbl_app_state'
);
SET @rename_statement = IF(
  @has_old_table = 1 AND @has_new_table = 0,
  'RENAME TABLE app_state TO tbl_app_state',
  'SELECT ''No app_state migration is required.'' AS result'
);
PREPARE tbl_prefix_migration FROM @rename_statement;
EXECUTE tbl_prefix_migration;
DEALLOCATE PREPARE tbl_prefix_migration;
