# CissyTech Payments API

A compact plain-PHP dashboard and integration API for CyberSource-hosted payment links. Direct card processing is available only as an explicit, PCI-gated option.

## What is included

- Open local dashboard for creating, sending, and refreshing payment links.
- Test Center for hosted-checkout testing, live invoice-status refreshes, webhook health, and signed-event visibility.
- `POST /api/v1/payment-links` JSON API for other systems.
- `POST /api/v1/payments` JSON API for direct CyberSource card authorizations, disabled by default.
- Dashboard-issued API keys, passed as `X-API-Key`.
- Signed `POST /webhooks/cybersource` receiver for CyberSource invoice status events.
- MySQL production schema in `database/schema.mysql.sql`.
- Local JSON store in `storage/data.json` because this PHP installation has no PDO driver enabled.

## Local setup

1. The dashboard is open for local testing. Protect it with authentication before putting it on a public server.
2. For local work, set `APP_URL="http://localhost:8000"` in your untracked `.env` file, then start the application:

   ```powershell
   php -S localhost:8000 router.php
   ```

3. Open `http://localhost:8000` and create a payment link.

The supplied `.env` uses the current CyberSource Test credentials. It is excluded by `.gitignore`; do not upload it or the log file.

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

The `201` response contains your internal payment-link ID, CyberSource invoice ID, payment URL, and current status. Retrieve the last local status with `GET /api/v1/payment-links/{id}`. To confirm a completed hosted payment immediately, call `GET /api/v1/payment-links/{id}?refresh=true`; this securely fetches the latest invoice status from CyberSource and returns `PAID` once CyberSource has completed it. Customer details are optional when you return the link yourself; `customer.email` is required if `send` is `true`.

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
    "firstName": "John",
    "lastName": "Doe",
    "address1": "1 Market St",
    "locality": "Kampala",
    "administrativeArea": "Central",
    "postalCode": "256",
    "country": "UG",
    "email": "customer@example.com"
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
https://mariam.cissytech.com/cardpayments/webhooks/cybersource
```

Set its health-check URL to:

```text
https://mariam.cissytech.com/cardpayments/webhooks/cybersource/health
```

Create a separate CyberSource Webhooks Digital Signature Key and set `CYBERSOURCE_WEBHOOK_KEY_ID` and `CYBERSOURCE_WEBHOOK_SHARED_SECRET` in `.env`. The receiver validates the `v-c-signature` HMAC, key ID, and timestamp before it records the event or updates a local invoice status. Subscribe to the `customerInvoicing` events `invoicing.customer.invoice.paid`, `invoicing.customer.invoice.partial-payment`, `invoicing.customer.invoice.cancel`, and `invoicing.customer.invoice.send`.

For local test work, open `/test-center`, open a hosted checkout URL, complete the CyberSource Test payment, and select **Refresh status**. A local `localhost` URL cannot receive CyberSource webhooks; automatic updates require deployment to a public HTTPS address and the signature key configuration above.

## Hosting at `mariam.cissytech.com/cardpayments`

The repository includes a root `.htaccess`, so it can be uploaded directly to the `cardpayments` web folder with WinSCP. Apache exposes only `public/`; your `.env`, source code, database schema, and storage stay outside the public URL.

On the hosted server set this value in `.env`:

```ini
APP_URL="https://mariam.cissytech.com/cardpayments"
APP_DEBUG="false"
```

The application generates dashboard links, form actions, assets, API documentation, the webhook callback, and the health-check URL with the `/cardpayments` prefix.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the precise WinSCP upload and server configuration steps.

For CyberSource Test, enable Invoicing in Business Center first. Create a webhook subscription with product ID `customerInvoicing`, event types `invoicing.customer.invoice.paid`, `invoicing.customer.invoice.partial-payment`, `invoicing.customer.invoice.cancel`, and `invoicing.customer.invoice.send`, and use the two URLs above. Save the webhook ID returned by CyberSource. After deployment, CyberSource checks the health URL and activates the subscription once it can reach it.

## Which CyberSource APIs this application uses

- **Invoicing API** — `POST /invoicing/v2/invoices`: the right default for this project because it already works with this Test MID and returns a CyberSource-hosted payment URL. Use it for your external clients.
- **Webhooks API** — configure it in CyberSource so completed, cancelled, partially paid, and sent invoices update automatically in CissyTech.
- **Payments API** — `POST /pts/v2/payments`: only for a separately approved PCI-compliant direct-card service. It is not the default integration for third-party clients.
- **Pay by Link API** — `/ipl/v2/payment-links`: consider it only after CyberSource confirms that Unified Checkout and Pay by Link are enabled for your MID. It is a separate CyberSource product from Invoicing.

## Findings

- Your CyberSource client now works when its wire-header order is `Host`, `Signature`, `Digest`, `v-c-merchant-id`, `v-c-date`, `Content-Type`.
- Payment links are the right default integration: customers enter cards on CyberSource's hosted page, which keeps PAN and CVV out of this application and third-party integrations.
- HTTP Signature is being retired by CyberSource, so plan a later migration to JWT/MLE before their deadline.
