-- Shared card and EFRIS state used by the plain-PHP repository layer.
-- Every application table uses the tbl_ prefix.
CREATE TABLE IF NOT EXISTS tbl_app_state (
  id TINYINT PRIMARY KEY,
  state JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
