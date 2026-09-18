-- Run this once only when upgrading a database created by an earlier version.
-- It copies the existing state into tbl_app_state and then removes app_state.
-- It is safe whether this file is imported before or after schema.mysql.sql.

CREATE TABLE IF NOT EXISTS tbl_app_state (
  id TINYINT PRIMARY KEY,
  state JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

SET @has_old_table = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'app_state'
);
SET @copy_statement = IF(
  @has_old_table = 1,
  'INSERT INTO tbl_app_state (id, state, updated_at) SELECT id, state, updated_at FROM app_state ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = VALUES(updated_at)',
  'SELECT ''No app_state migration is required.'' AS result'
);
PREPARE tbl_prefix_copy FROM @copy_statement;
EXECUTE tbl_prefix_copy;
DEALLOCATE PREPARE tbl_prefix_copy;

SET @drop_statement = IF(@has_old_table = 1, 'DROP TABLE app_state', 'SELECT 1');
PREPARE tbl_prefix_drop FROM @drop_statement;
EXECUTE tbl_prefix_drop;
DEALLOCATE PREPARE tbl_prefix_drop;
