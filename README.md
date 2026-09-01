# CissyTech Payments API

A compact plain-PHP dashboard and integration API for CyberSource-hosted payment links. Direct card processing is available only as an explicit, PCI-gated option.

## What is included

- Open local dashboard for creating, sending, and refreshing payment links.
- `POST /api/v1/payment-links` JSON API for other systems.
- `POST /api/v1/payments` JSON API for direct CyberSource card authorizations, disabled by default.
- Dashboard-issued API keys, passed as `X-API-Key`.
- Signed `POST /webhooks/cybersource` receiver for CyberSource invoice status events.
- MySQL production schema in `database/schema.mysql.sql`.
- Local JSON store in `storage/data.json` because this PHP installation has no PDO driver enabled.

## Local setup

1. The dashboard is open for local testing. Protect it with authentication before putting it on a public server.
2. Start the application:

   ```powershell
   php -S localhost:8000 router.php
   ```

3. Open `http://localhost:8000` and create a payment link.

Set `CYBERSOURCE_ENV="live"` and add production REST credentials generated in CyberSource Production Business Center. Test and production REST keys are separate. `.env` and log files are excluded by `.gitignore`; do not commit either.

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
  "invoice_number": "ORDER-1001",
  "description": "Order payment",
  "due_date": "2026-09-30",
  "send": false,
  "allow_partial": false,
  "customer": { "name": "Mariam Tukas", "email": "mariam@example.com" }
}
```

The `201` response contains only `invoice_number` and `payment_url`. Keep the invoice number and use `GET /api/v1/payment-links/{id}?refresh=true` to retrieve the latest invoice status before fulfilling an order. Customer details are optional when you return the link yourself; `customer.email` is required if `send` is `true`.

### Direct payment API

Use the same API key when a PCI-compliant server needs to submit a card authorization directly to CyberSource. This endpoint stays disabled until `DIRECT_CARD_PAYMENTS_ENABLED="true"` is explicitly set in `.env`.

```http
POST /api/v1/payments
X-API-Key: plk_test_...
Content-Type: application/json
```

```json
{
  "amount": "1000",
  "currency": "UGX",
  "reference": "ORDER-1002",
  "card": {
    "number": "4111111111111111",
    "expiration_month": "12",
    "expiration_year": "2031",
    "security_code": "123"
  },
  "bill_to": {
    "firstName": "Samson",
    "lastName": "Kwiki",
    "address1": "Ntinda",
    "locality": "Kampala",
    "administrativeArea": "Central",
    "postalCode": "256",
    "country": "UG",
    "email": "maiam@cissy.com"
  }
}
```

The response returns the CyberSource payment ID, processor transaction ID, status, amount, currency, and reference. Card number and security code are forwarded to CyberSource only; they are not persisted in the application or request log.

> **Important:** Direct payments accept PAN and CVV, putting the calling system and this API in PCI DSS scope. Keep this endpoint server-to-server, never call it from a browser, and complete the required PCI controls before production. For the lowest-risk integration, use CyberSource-hosted payment links instead.

## Database deployment

Run `database/schema.mysql.sql` on MySQL 8+ when you deploy, enable `pdo_mysql`, and set `DB_DSN` (for example `mysql:host=127.0.0.1;dbname=paylink_lab;charset=utf8mb4`), `DB_USER`, and `DB_PASSWORD` in `.env`. The repository layer automatically switches from local JSON storage to MySQL; no controller or API changes are needed. This PHP runtime reports `PDO drivers =>` empty, so it correctly uses the local JSON store for immediate testing.

## Webhooks

Set CyberSource's webhook callback URL to:

```text
https://your-domain.example/webhooks/cybersource
```

Set its health-check URL to:

```text
https://your-domain.example/webhooks/cybersource/health
```

Create a separate CyberSource Webhooks Digital Signature Key and set `CYBERSOURCE_WEBHOOK_KEY_ID` and `CYBERSOURCE_WEBHOOK_SHARED_SECRET` in `.env`. The receiver validates the `v-c-signature` HMAC, key ID, and timestamp before it records the event or updates a local invoice status. Subscribe to the `customerInvoicing` events `invoicing.customer.invoice.paid`, `invoicing.customer.invoice.partial-payment`, `invoicing.customer.invoice.cancel`, and `invoicing.customer.invoice.send`.

Use **Refresh** on the invoice overview to retrieve the latest CyberSource payment status. Automatic updates require a public HTTPS webhook URL and the signature key configuration above.

## Which CyberSource APIs this application uses

- **Invoicing API** — `POST /invoicing/v2/invoices`: the right default for this project because it already works with this Test MID and returns a CyberSource-hosted payment URL. Use it for your external clients.
- **Webhooks API** — configure it in CyberSource so completed, cancelled, partially paid, and sent invoices update automatically in CissyTech.
- **Payments API** — `POST /pts/v2/payments`: only for a separately approved PCI-compliant direct-card service. It is not the default integration for third-party clients.
- **Pay by Link API** — `/ipl/v2/payment-links`: consider it only after CyberSource confirms that Unified Checkout and Pay by Link are enabled for your MID. It is a separate CyberSource product from Invoicing.

## Findings

- Production requests go to `https://api.cybersource.com`; add production credentials from Production Business Center before creating an invoice.
- Payment links are the right default integration: customers enter cards on CyberSource's hosted page, which keeps PAN and CVV out of this application and third-party integrations.
- HTTP Signature is being retired by CyberSource, so plan a later migration to JWT/MLE before their deadline.
