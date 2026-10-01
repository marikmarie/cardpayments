-- Import legacy hosted-card rows from tbl_app_state into tbl_pegasus_cards.
-- Run this after the current pegasus/database/schema.mysql.sql on MySQL 8+.

INSERT INTO tbl_pegasus_cards (
  vendor_transaction_id, amount, currency, description, customer_name, customer_email,
  return_url, source_ip, status, gateway_status, gateway_reason, pegpay_transaction_id,
  response_signature_valid, status_query_http_status, status_query_requested_at,
  status_queried_at, returned_at, created_at, updated_at
)
SELECT
  card.vendor_transaction_id, card.amount, card.currency, card.description,
  NULLIF(card.customer_name, ''), NULLIF(card.customer_email, ''), card.return_url,
  card.source_ip, COALESCE(card.status, 'PENDING'), card.gateway_status,
  card.gateway_reason, card.pegpay_transaction_id, card.response_signature_valid,
  card.status_query_http_status,
  STR_TO_DATE(LEFT(card.status_query_requested_at, 19), '%Y-%m-%dT%H:%i:%s'),
  STR_TO_DATE(LEFT(card.status_queried_at, 19), '%Y-%m-%dT%H:%i:%s'),
  STR_TO_DATE(LEFT(card.returned_at, 19), '%Y-%m-%dT%H:%i:%s'),
  COALESCE(STR_TO_DATE(LEFT(card.created_at, 19), '%Y-%m-%dT%H:%i:%s'), UTC_TIMESTAMP(6)),
  COALESCE(STR_TO_DATE(LEFT(card.updated_at, 19), '%Y-%m-%dT%H:%i:%s'), UTC_TIMESTAMP(6))
FROM tbl_app_state AS app_state
JOIN JSON_TABLE(app_state.state, '$.pegasus_card_collections.*'
  COLUMNS (
    vendor_transaction_id VARCHAR(60) PATH '$.id',
    amount DECIMAL(15, 2) PATH '$.amount',
    currency CHAR(3) PATH '$.currency',
    description VARCHAR(200) PATH '$.description',
    customer_name VARCHAR(120) PATH '$.customer_name',
    customer_email VARCHAR(160) PATH '$.customer_email',
    return_url VARCHAR(500) PATH '$.return_url',
    source_ip VARCHAR(45) PATH '$.source_ip',
    status VARCHAR(20) PATH '$.status',
    gateway_status VARCHAR(20) PATH '$.gateway_status',
    gateway_reason VARCHAR(1000) PATH '$.gateway_reason',
    pegpay_transaction_id VARCHAR(100) PATH '$.pegpay_transaction_id',
    response_signature_valid TINYINT PATH '$.response_signature_valid',
    status_query_http_status SMALLINT PATH '$.status_query_http_status',
    status_query_requested_at VARCHAR(40) PATH '$.status_query_requested_at',
    status_queried_at VARCHAR(40) PATH '$.status_queried_at',
    returned_at VARCHAR(40) PATH '$.returned_at',
    created_at VARCHAR(40) PATH '$.created_at',
    updated_at VARCHAR(40) PATH '$.updated_at'
  )
) AS card ON TRUE
WHERE card.vendor_transaction_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  amount = VALUES(amount), currency = VALUES(currency), description = VALUES(description),
  customer_name = VALUES(customer_name), customer_email = VALUES(customer_email),
  return_url = VALUES(return_url), source_ip = VALUES(source_ip), status = VALUES(status),
  gateway_status = VALUES(gateway_status), gateway_reason = VALUES(gateway_reason),
  pegpay_transaction_id = VALUES(pegpay_transaction_id),
  response_signature_valid = VALUES(response_signature_valid),
  status_query_http_status = VALUES(status_query_http_status),
  status_query_requested_at = VALUES(status_query_requested_at),
  status_queried_at = VALUES(status_queried_at), returned_at = VALUES(returned_at),
  updated_at = VALUES(updated_at);
