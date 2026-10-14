# PesaWay gateway

The PesaWay module implements the documented direct API operations that are applicable to this application:

- OAuth client-credential token acquisition and active channel discovery.
- Mobile-money B2C, B2B, and C2B requests, OTP authorisation, and transaction status queries.
- Bank payouts and bank transaction status queries.
- Airtime delivery, transaction history pulls, merchant balance, bulk SMS, and SMS balance.
- Refund initiation with the timestamped HMAC signature required by PesaWay, plus generic and refund callback verification.

FX and crypto products are deliberately not implemented. The UI has no FX or crypto tab, requests reject known crypto currencies, and the active-channel response is filtered before display.

## Get PesaWay credentials

1. Email [PesaWay support](mailto:support@pesaway.com) with your company details and a contact person to start onboarding.
2. After onboarding, PesaWay sends the sandbox **Client ID** and **Client Secret**. Put them in `PESAWAY_CONSUMER_KEY` and `PESAWAY_CONSUMER_SECRET` respectively; PesaWay uses those names in its token request.
3. Ask PesaWay for the merchant code for the service and region you will test, then set `PESAWAY_MERCHANT_CODE`. It is needed for payment, collection, bank, airtime, balance, and refund requests.
4. Keep `PESAWAY_REGION="ke"` for Kenya. Use the region PesaWay enables for your merchant if you later test another country.
5. Set `PESAWAY_CALLBACK_URL` to your public HTTPS callback URL. Use **Overview → Check OAuth connection** to confirm the sandbox credentials before testing other tabs.

Do not create the Client ID, Client Secret, merchant code, or access token yourself. PesaWay issues the first three; the tester obtains the short-lived access token automatically. If PesaWay enables signed callbacks, request the shared callback signature key and set `PESAWAY_CALLBACK_SECRET`. For refunds, ask support to enable refunds and provide both the refund signing secret and the separate refund-callback signing secret.

After the PesaWay developer test session, PesaWay creates the production application and emails the production credentials. Replace the sandbox URL and credentials with those values, then give PesaWay the public HTTPS callback URL for live transaction results.

| PesaWay value | `.env` value |
| --- | --- |
| Client ID | `PESAWAY_CONSUMER_KEY` |
| Client Secret | `PESAWAY_CONSUMER_SECRET` |
| Merchant code | `PESAWAY_MERCHANT_CODE` |
| Shared callback signature key, when enabled | `PESAWAY_CALLBACK_SECRET` |
| Refund request signing secret, when refunds are enabled | `PESAWAY_REFUND_SECRET` |
| Refund callback signing secret, when refunds are enabled | `PESAWAY_REFUND_CALLBACK_SECRET` |

## Implemented PesaWay operations

The protected local API exposes each non-FX/non-crypto operation from the PesaWay guide:

- `POST /api/v1/pesaway/channels` — active payment channels.
- `POST /api/v1/pesaway/mobile/b2c`, `/mobile/b2b`, and `/mobile/c2b` — mobile payouts and collections.
- `POST /api/v1/pesaway/mobile/authorize` and `/mobile/query` — OTP authorisation and transaction status.
- `POST /api/v1/pesaway/bank/payout` and `/bank/query` — bank transfer and status.
- `POST /api/v1/pesaway/airtime`, `/transactions/pull`, `/refunds`, `/balance`, `/sms`, and `/sms/balance`.

The browser tester at `/pesaway-tester` provides matching tabs for every operation. The token request remains internal because it uses merchant credentials; the **Overview** tab verifies it without showing it.

## Configure

Set these values only in `.env`:

```ini
PESAWAY_BASE_URL="https://api.sandbox.pesaway.com/api/v1"
PESAWAY_CONSUMER_KEY=""
PESAWAY_CONSUMER_SECRET=""
PESAWAY_REGION="ke"
PESAWAY_MERCHANT_CODE=""
PESAWAY_CALLBACK_URL="https://your-domain.example/webhooks/pesaway"
PESAWAY_CALLBACK_SECRET=""
PESAWAY_REFUND_SECRET=""
PESAWAY_REFUND_CALLBACK_SECRET=""
PESAWAY_TIMEOUT_SECONDS="30"
```

`PESAWAY_CALLBACK_SECRET` verifies the normal `Signature` callback header when PesaWay has enabled it for the merchant. `PESAWAY_REFUND_SECRET` signs outbound refund requests, and `PESAWAY_REFUND_CALLBACK_SECRET` verifies refund callbacks with `X-Timestamp` and `X-Signature`. Keep all of them out of version control.

Kenya is the default region (`ke`), so the tester starts with KES, `254` phone-number examples, and the `MPESA` channel. To use another supported market, set `PESAWAY_REGION` to `ug`, `tz`, `zm`, `gh`, or `cm` and enter the active channel and currency returned for that region.

The PesaWay dashboard workspace is `/pesaway-tester`. Its tabs show only the selected operation group. `/webhooks/pesaway` is the callback receiver and `/webhooks/pesaway/health` is its health endpoint. The protected local API contract is at `/api/v1/pesaway/openapi.json` and requires a dashboard-issued `X-API-Key`.

Every outbound call writes a redacted request record and a response record to the application store under `pesaway_activity`. Tokens, client secrets, signatures, OTPs, phone numbers, and account numbers are masked before they are persisted or shown in the Logs tab.
