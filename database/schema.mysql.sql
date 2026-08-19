CREATE DATABASE IF NOT EXISTS paylink_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE paylink_lab;

-- Compact application state used by the plain-PHP repository layer. It keeps
-- the local JSON and MySQL storage contracts identical.
CREATE TABLE app_state (
  id TINYINT PRIMARY KEY,
  state JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE api_keys (
  id CHAR(24) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE payment_links (
  id CHAR(24) PRIMARY KEY,
  provider_invoice_id VARCHAR(100) NOT NULL UNIQUE,
  invoice_number VARCHAR(100) NOT NULL UNIQUE,
  payment_url TEXT NOT NULL,
  customer_name VARCHAR(160) NOT NULL,
  customer_email VARCHAR(254) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(13,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(40) NOT NULL,
  due_date DATE NULL,
  allow_partial BOOLEAN NOT NULL DEFAULT FALSE,
  provider_data JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX payment_links_status_idx (status),
  INDEX payment_links_created_idx (created_at)
);

CREATE TABLE transactions (
  id CHAR(24) PRIMARY KEY,
  provider_payment_id VARCHAR(100) NOT NULL UNIQUE,
  processor_transaction_id VARCHAR(100) NULL,
  reference VARCHAR(100) NULL,
  amount DECIMAL(13,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX transactions_status_idx (status),
  INDEX transactions_created_idx (created_at)
);

CREATE TABLE webhook_events (
  id CHAR(24) PRIMARY KEY,
  provider_event_id VARCHAR(100) NULL UNIQUE,
  payload JSON NOT NULL,
  received_at DATETIME NOT NULL,
  INDEX webhook_events_received_idx (received_at)
);
