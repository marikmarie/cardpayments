# GoDigital Tanzania mobile-money integration

This module connects CissyTech Payments to the GoDigital Payments API v1. It is kept separate from the PegPay and CyberSource modules so its credentials, routes, test screens, and operational guidance are easy to manage independently.

## What is implemented

- OAuth 2.0 client-credentials token request: `POST /oauth/token`.
- C2B collection: `POST /payments/collections/push`.
- B2C disbursement: `POST /payments/disbursements`.
- Payment status: `GET /payments/{reference}`.
- Wallet balance: `GET /payments/wallets/balance/{clientId}`.
- Recipient name check (v1.1.4): `GET /payments/name-check?providerCode={providerCode}&msisdn={msisdn}`.
- GoDigital-required request headers: bearer token, client ID, request ID, idempotency key, timestamp, nonce, and Base64 SHA-256 content hash.
- Public callback receiver at `/webhooks/godigital`, including duplicate callback and duplicate transaction protection.
- A dashboard tester at `/godigital-tester` with OAuth, collection, payout, status, balance, name check, callback, and Logs tabs.

The integration does not return OAuth access tokens, client secrets, or provider credentials to browsers or API callers.

The v1.1.4 name-check endpoint is intentionally separate from payment creation: GoDigital specifies `Accept`, `X-Client-Id`, and `X-Request-Id` for that lookup. The implementation follows that header set and does not add payment OAuth headers to the request.

## Before GoDigital testing

1. Ask GoDigital for the **UAT** base URL, client ID, client secret, merchant ID, enabled mobile-money providers, wallet funding requirements, and any per-operation limits.
2. Give GoDigital the deployed server's public **outbound IP address**. Their OAuth endpoint and payment endpoints are IP allow-listed. `PGW-1009` means the IP is missing from the allow-list.
3. Create a public HTTPS callback URL and give it to GoDigital. The default is `https://your-domain/webhooks/godigital` when `APP_URL` is set.
4. If GoDigital enables callback signatures, set the callback secret they issue. The receiver verifies the Base64 HMAC-SHA256 of the raw callback body, as described in the supplied guide. It also verifies the optional callback content hash when present.
5. Use only GoDigital-approved UAT MSISDNs and providers during UAT. Do not run payout tests against a live customer number.

## Configuration

Add these to the private `.env` file on the server; do not commit real values:

```ini
# The URL supplied by GoDigital must include /api/v1.
GODIGITAL_BASE_URL="https://your-godigital-host/api/v1"
GODIGITAL_CLIENT_ID=""
GODIGITAL_CLIENT_SECRET=""
GODIGITAL_MERCHANT_ID=""

# Leave blank to derive APP_URL/webhooks/godigital.
GODIGITAL_CALLBACK_URL="https://your-public-domain/webhooks/godigital"
# Leave blank unless GoDigital enables callback signatures for your merchant.
GODIGITAL_CALLBACK_SECRET=""
GODIGITAL_TIMEOUT_SECONDS="30"
```

Keep UAT and production values separate. Changing between environments requires changing all four provider values together: base URL, client ID, client secret, and merchant ID.

## Dashboard test workflow

1. Sign in to the CissyTech dashboard, open **GoDigital** in the sidebar, then open **OAuth**.
2. Verify the three readiness cards: gateway URL, OAuth credentials, and HTTPS callback.
3. Select **Check OAuth connection**. Fix missing configuration, invalid credentials (`PGW-1001`), or IP allow-list errors (`PGW-1009`) before continuing.
4. In **C2B collection**, enter a unique reference, an approved test MSISDN in `2557XXXXXXXX` format, provider, and TZS amount. Submit it, then wait for the callback or use **Status**.
5. In **B2C payout**, use an approved test recipient and repeat the final-status check.
6. Use **Wallet** to confirm the wallet balance before payout tests.
7. In **Callbacks**, copy the shown endpoint into the GoDigital merchant configuration. It returns `200 {"status":"RECEIVED"}` when it accepts a delivery.

An accepted C2B request is not a completed payment. Never credit a wallet, fulfil an order, or mark an invoice paid until the callback or status endpoint reports the final expected provider state.

## CissyTech server API

Create a dashboard API key and send it as `X-API-Key`. API callers should also send a stable `Idempotency-Key` for every create request; repeating the same key with the same data returns the original local record, while reusing it with changed data returns HTTP 409.

| Method | Route | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/godigital/collections` | Request a C2B collection |
| `POST` | `/api/v1/godigital/disbursements` | Request a B2C payout |
| `GET` | `/api/v1/godigital/payments/{reference}` | Check provider status |
| `GET` | `/api/v1/godigital/wallets/balance/{clientId}` | Check wallet balance |
| `GET` | `/api/v1/godigital/name-check?provider_code=VODACOM&msisdn=255754123456` | Verify a recipient name |
| `GET` | `/api/v1/godigital/openapi.json` | OpenAPI document |
| `POST` | `/webhooks/godigital` | GoDigital callback receiver |
| `GET` | `/webhooks/godigital/health` | Callback health check |

Example collection request:

```http
POST /api/v1/godigital/collections
X-API-Key: plk_test_...
Idempotency-Key: gd-order-1001-v1
Content-Type: application/json
```

```json
{
  "amount": "10000.00",
  "provider_code": "VODACOM",
  "msisdn": "255754123456",
  "reference": "ORDER-1001",
  "currency": "TZS",
  "narration": "Wallet top-up"
}
```

## Production readiness checklist

- Production GoDigital client credentials and merchant ID are in the production `.env` only.
- GoDigital has allow-listed the production server's public outbound IP.
- `GODIGITAL_CALLBACK_URL` is public HTTPS, resolves correctly, and returns HTTP 200 for a valid callback.
- The callback is monitored; GoDigital retries a timeout or non-2xx response.
- Your business logic waits for a final provider status before fulfilment.
- A stable idempotency key is retained with every payment attempt.
- Provider limits, wallet balance, enabled providers, and live MSISDN consent have been verified with GoDigital.
