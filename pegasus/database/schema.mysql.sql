-- Pegasus transaction history and redacted provider activity.
-- Import this after card/database/schema.mysql.sql on MySQL 8+.

-- Hosted-card collections use this dedicated table. It replaces the former
-- pegasus_card_collections object inside tbl_app_state.state.
CREATE TABLE IF NOT EXISTS tbl_pegasus_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_transaction_id VARCHAR(60) NOT NULL,
  amount DECIMAL(15, 2) NOT NULL,
  currency CHAR(3) NOT NULL,
  description VARCHAR(200) NOT NULL,
  customer_name VARCHAR(120) NULL,
  customer_email VARCHAR(160) NULL,
  return_url VARCHAR(500) NOT NULL,
  source_ip VARCHAR(45) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  gateway_status VARCHAR(20) NULL,
  gateway_reason VARCHAR(1000) NULL,
  pegpay_transaction_id VARCHAR(100) NULL,
  response_signature_valid TINYINT(1) NULL,
  status_query_response JSON NULL,
  status_query_http_status SMALLINT UNSIGNED NULL,
  status_query_requested_at DATETIME(6) NULL,
  status_queried_at DATETIME(6) NULL,
  returned_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_tbl_pegasus_cards_vendor_reference (vendor_transaction_id),
  KEY ix_tbl_pegasus_cards_status_created (status, created_at),
  KEY ix_tbl_pegasus_cards_provider_transaction (pegpay_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_pegasus_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_transaction_id VARCHAR(60) NOT NULL,
  transaction_type VARCHAR(10) NOT NULL,
  amount DECIMAL(15, 0) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'UGX',
  from_account VARCHAR(30) NULL,
  to_account VARCHAR(30) NULL,
  from_network VARCHAR(16) NULL,
  to_network VARCHAR(16) NULL,
  payment_code VARCHAR(4) NULL,
  customer_reference VARCHAR(100) NULL,
  customer_name VARCHAR(100) NULL,
  narration VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  provider_status_code VARCHAR(20) NULL,
  provider_status_description VARCHAR(255) NULL,
  pegpay_id VARCHAR(100) NULL,
  telecom_id VARCHAR(100) NULL,
  request_payload JSON NULL,
  provider_response JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_tbl_pegasus_transactions_vendor_reference (vendor_transaction_id),
  KEY ix_tbl_pegasus_transactions_status_created (status, created_at),
  KEY ix_tbl_pegasus_transactions_provider_code (provider_status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_pegasus_api_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_transaction_id VARCHAR(60) NULL,
  log_type VARCHAR(10) NOT NULL,
  request_type VARCHAR(64) NOT NULL,
  http_method VARCHAR(10) NULL,
  endpoint VARCHAR(500) NULL,
  http_status SMALLINT UNSIGNED NULL,
  provider_status_code VARCHAR(20) NULL,
  provider_status_description VARCHAR(255) NULL,
  payload JSON NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_tbl_pegasus_api_logs_vendor_created (vendor_transaction_id, created_at),
  KEY ix_tbl_pegasus_api_logs_request_created (request_type, created_at),
  KEY ix_tbl_pegasus_api_logs_provider_code (provider_status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
