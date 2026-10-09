# PesaWay gateway

The PesaWay module implements the documented direct API operations that are applicable to this application:

- OAuth client-credential token acquisition and active channel discovery.
- Mobile-money B2C, B2B, and C2B requests, OTP authorisation, and transaction status queries.
- Bank payouts and bank transaction status queries.
- Airtime delivery, transaction history pulls, merchant balance, bulk SMS, and SMS balance.
- Refund initiation with the timestamped HMAC signature required by PesaWay, plus generic and refund callback verification.

FX and crypto products are deliberately not implemented. The UI has no FX or crypto tab, requests reject known crypto currencies, and the active-channel response is filtered before display.

## Configure

Set these values only in `.env`:

```ini
PESAWAY_BASE_URL="https://api.sandbox.pesaway.com/api/v1"
PESAWAY_CONSUMER_KEY=""
PESAWAY_CONSUMER_SECRET=""
PESAWAY_REGION="tz"
PESAWAY_MERCHANT_CODE=""
PESAWAY_CALLBACK_URL="https://your-domain.example/webhooks/pesaway"
PESAWAY_CALLBACK_SECRET=""
PESAWAY_REFUND_SECRET=""
PESAWAY_REFUND_CALLBACK_SECRET=""
PESAWAY_TIMEOUT_SECONDS="30"
```

`PESAWAY_CALLBACK_SECRET` verifies the normal `Signature` callback header when PesaWay has enabled it for the merchant. `PESAWAY_REFUND_SECRET` signs outbound refund requests, and `PESAWAY_REFUND_CALLBACK_SECRET` verifies refund callbacks with `X-Timestamp` and `X-Signature`. Keep all of them out of version control.

The PesaWay dashboard workspace is `/pesaway-tester`; the collapsible PesaWay sidebar group opens its matching tab. `/webhooks/pesaway` is the callback receiver and `/webhooks/pesaway/health` is its health endpoint. The protected local API contract is at `/api/v1/pesaway/openapi.json` and requires a dashboard-issued `X-API-Key`.

Every outbound call writes a redacted request record and a response record to the application store under `pesaway_activity`. Tokens, client secrets, signatures, OTPs, phone numbers, and account numbers are masked before they are persisted or shown in the Logs tab.
