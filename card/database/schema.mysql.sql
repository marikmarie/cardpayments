-- Card module state used by the plain-PHP repository layer. It keeps
-- the local JSON and MySQL storage contracts identical.
CREATE TABLE IF NOT EXISTS app_state (
  id TINYINT PRIMARY KEY,
  state JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
