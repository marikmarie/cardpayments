# CissyTech Payments API

A compact plain-PHP dashboard and integration API for CyberSource-hosted payment links.

## What is included

- Token-protected dashboard for creating, sending, and refreshing payment links.
- `POST /api/v1/payment-links` JSON API for other systems.
- Dashboard-issued API keys, passed as `X-API-Key`.
- Signed `POST /webhooks/cybersource` receiver for CyberSource invoice status events.
- A separate `efris/` gateway module and OpenAPI contract for tenant-scoped POS/ERP fiscalisation testing.
- A separate `godigital/` gateway module for GoDigital Tanzania mobile-money collections, payouts, callbacks, and UAT testing.
- MySQL production schemas in `card/database/schema.mysql.sql` and `pegasus/database/schema.mysql.sql`.
- Local JSON store in `storage/data.json` because this PHP installation has no PDO driver enabled.

## Local setup

1. Set a private `DASHBOARD_ACCESS_TOKEN` in `.env`. The login page requires it before displaying any dashboard page or sidebar.
2. Start the application:

   ```powershell
   php -S localhost:8000 router.php
   ```

3. Open `http://localhost:8000` and create a payment link.

Set `CYBERSOURCE_ENV="live"` and add production REST credentials generated in CyberSource Production Business Center. Test and production REST keys are separate. `.env` and log files are excluded by `.gitignore`; do not commit either.

## Payment ID format

New CissyTech payment, request, invoice, and integration IDs use exactly 10 uppercase characters: `PMT` followed by seven letters or digits, for example `PMT1234567`. Provider-issued IDs and existing historical references remain available for status checks.

## External API

Open the built-in browser reference at `http://localhost:8000/developers/api`, or import the OpenAPI 3.1 document from `http://localhost:8000/api/v1/openapi.json`.

Create a dashboard API key, then send this request:

```http
POST /api/v1/payment-links
X-API-Key: plk_test_...
Content-Type: application/json
```

```json
{
  "amount": "100000",
  "currency": "UGX",
  "invoice_number": "PMT1234567",
  "description": "Order payment",
  "due_date": "2026-09-30",
  "send": false,
  "allow_partial": false,
  "customer": { "name": "Mariam Tukas", "email": "mariam@example.com" }
}
```

The `201` response contains only `invoice_number` and `payment_url`. Keep the invoice number and use `GET /api/v1/payment-links/{invoice_number}?refresh=true` to retrieve the latest invoice status before fulfilling an order. Customer details are optional when you return the link yourself; `customer.email` is required if `send` is `true`.

## EFRIS gateway

The EFRIS work is kept in [`efris/README.md`](efris/README.md), separate from card-payment code. It provides a tenant-scoped API contract at `/api/v1/efris/openapi.json` and a safe mock mode for vendor integration tests. It does not create a URA fiscal document until URA test onboarding and the current encrypted/signed protocol implementation have been completed.

## GoDigital gateway

The GoDigital integration is documented in [`godigital/README.md`](godigital/README.md). Its dashboard tester follows the v1.1.4 contract and has separate tabs for connection, C2B collections, B2C payouts, status, wallet balance, recipient name check, callback setup, and logs. Configure its credentials only in `.env`, allow-list the deployed server's public outbound IP with GoDigital, and use the public HTTPS callback `/webhooks/godigital`.

## Database deployment

Run `card/database/schema.mysql.sql` and `pegasus/database/schema.mysql.sql` on MySQL 8+ when you deploy, enable `pdo_mysql`, and set `DB_DSN` (for example `mysql:host=127.0.0.1;dbname=paylink_lab;charset=utf8mb4`), `DB_USER`, and `DB_PASSWORD` in `.env`. The repository layer automatically switches from local JSON storage to MySQL; no controller or API changes are needed. Every physical application table uses the `tbl_` prefix: `tbl_app_state`, `tbl_pegasus_transactions`, and `tbl_pegasus_api_logs`. Hosted card collection records are held in `tbl_app_state.state.pegasus_card_collections`; the redacted PegPay requests and responses are in `tbl_pegasus_api_logs` and, by default, `storage/pegasus-card.log`. `tbl_pegasus_transactions` holds the regular PegPay Payments and Collections API transactions. For an existing installation that has `app_state`, run `card/database/migrate-to-tbl-prefix.mysql.sql` once before deploying this version; it copies the existing state, then removes the old unprefixed table. This PHP runtime reports `PDO drivers =>` empty, so it correctly uses the local JSON store for immediate testing.

## PegPay Web card status

Set `PEGASUS_WEB_STATUS_URL` to the HTTPS `QueryStatus.aspx` address supplied by Pegasus. It is separate from the Payments and Collections API. For the supplied test checkout URL `https://test.pegasus.co.ug:8019/PaymentsGatewayTest/Default.aspx`, use `https://test.pegasus.co.ug:8019/PaymentsGatewayTest/QueryStatus.aspx`; the guide's older `TestPegasusPaymentsGateway` example returns 404. The Card collections **Status** tab sends the documented `MerchantId`, `VendorCode`, `Pswd`, and original `VendorTranId` query. `Pswd` is `HMAC-SHA256(PEGASUS_WEB_PASSWORD, PEGASUS_WEB_SECRET_CODE)`, never the plaintext password. The status screen and card log show the complete request URL with that value redacted, plus the PegPay response. Pending collections cannot be queried more often than every five seconds.

## Vault card checkout API

Vault web and mobile clients should call their own server-side integration, not this
API directly. That server sends its dashboard-generated `X-API-Key` to create a
hosted collection:

```http
POST /api/v1/pegasus/card-collections
X-API-Key: plk_test_...
Content-Type: application/json

{
  "amount": "25000",
  "currency": "UGX",
  "description": "Collecto Vault wallet top-up",
  "customer_name": "Mariam Tukas"
}
```

The response includes `data.id` and `data.checkout_url`. Open `checkout_url` in the
customer's browser or secure in-app browser. It is a CissyTech page that creates the
signed PegPay form server-side, then forwards the customer to PegPay; clients never
receive PegPay credentials, signatures, or card data. Retrieve the result with
`GET /api/v1/pegasus/card-collections/{id}?refresh=true`. The `refresh` query asks
PegPay for a verified status and is rate-limited to one pending check every five
seconds.

## Webhooks

Set CyberSource's webhook callback URL to:

```text
https://your-domain.example/webhooks/cybersource
```

Set its health-check URL to:

```text
https://your-domain.example/webhooks/cybersource/health
```

Create a separate CyberSource Webhooks Digital Signature Key and set `CYBERSOURCE_WEBHOOK_KEY_ID` and `CYBERSOURCE_WEBHOOK_SHARED_SECRET` in `.env`. The receiver validates the `v-c-signature` HMAC, key ID, and timestamp before it updates a local invoice status. Subscribe to the `customerInvoicing` events `invoicing.customer.invoice.paid`, `invoicing.customer.invoice.partial-payment`, `invoicing.customer.invoice.cancel`, and `invoicing.customer.invoice.send`.

Use **Refresh** on the invoice overview to retrieve the latest CyberSource payment status. Automatic updates require a public HTTPS webhook URL and the signature key configuration above.

## Which CyberSource APIs this application uses

- **Invoicing API** — `POST /invoicing/v2/invoices`: the right default for this project because it already works with this Test MID and returns a CyberSource-hosted payment URL. Use it for your external clients.
- **Webhooks API** — configure it in CyberSource so completed, cancelled, partially paid, and sent invoices update automatically in CissyTech.
- **Pay by Link API** — `/ipl/v2/payment-links`: consider it only after CyberSource confirms that Unified Checkout and Pay by Link are enabled for your MID. It is a separate CyberSource product from Invoicing.

## Absa / CyberSource live 401

The card-link flow currently calls CyberSource's **Invoicing API** (`/invoicing/v2/invoices`) using a Production shared-secret REST key pair. A `401 Unauthorized` is returned before CyberSource evaluates an invoice ID, amount, or customer data. Use a Production **shared-secret REST** key ID and Base64 secret created for the same transacting MID in `CYBERSOURCE_MERCHANT_ID`; webhook keys, JWT certificates, and keys from a different MID or test environment do not authenticate this request. Ask Absa/CyberSource to confirm that the Invoicing API is enabled for that MID.

The supplied Unified Checkout guide describes a separate integration: it creates a capture-context JWT through `POST /uc/v1/sessions`, then renders CyberSource's browser SDK. It must be enabled for the MID, have card payment options configured, and use public HTTPS target origins. Enabling Unified Checkout does not grant access to the Invoicing API, and moving this project to that flow requires replacing its invoice-link lifecycle with the Sessions API and browser SDK.

The dashboard now records CyberSource's response correlation ID when supplied, so include the **Support ID** shown in the Absa card log when escalating a rejected live request.

## Findings

- Production requests go to `https://api.cybersource.com`; add production credentials from Production Business Center before creating an invoice.
- Payment links are the right default integration: customers enter cards on CyberSource's hosted page, which keeps PAN and CVV out of this application and third-party integrations.
- HTTP Signature is being retired by CyberSource, so plan a later migration to JWT/MLE before their deadline.
