# MTN MoMo Pay gateway

This module replaces the legacy `momopay.cissytech.com` scripts with one small server-side implementation that matches the existing gateway structure.

It supports the operations supplied in the MTN collection: Collection token, Request to Pay, collection status, collection balance, account-holder active/basic-info checks, Collection v2 withdrawal, Disbursement token, Transfer, transfer status, and disbursement balance. Credentials never leave the server, and every outbound request, response, and callback is logged after masking secrets and customer numbers.

## Configure

Provision a Collection API user/key and a Disbursement API user/key in the MTN MoMo portal. Use their matching subscription keys, then set only these values in `.env`:

```ini
MOMOPAY_BASE_URL="https://sandbox.momodeveloper.mtn.com"
MOMOPAY_TARGET_ENVIRONMENT="sandbox"
MOMOPAY_DEFAULT_CURRENCY="UGX"
MOMOPAY_COLLECTION_SUBSCRIPTION_KEY=""
MOMOPAY_COLLECTION_API_USER=""
MOMOPAY_COLLECTION_API_KEY=""
MOMOPAY_DISBURSEMENT_SUBSCRIPTION_KEY=""
MOMOPAY_DISBURSEMENT_API_USER=""
MOMOPAY_DISBURSEMENT_API_KEY=""
MOMOPAY_CALLBACK_URL="https://your-domain.example/webhooks/momopay"
MOMOPAY_TIMEOUT_SECONDS="30"
```

For production, replace the base URL, target environment, and every product credential with the production values provided by MTN. Register the public callback host with MTN; the receiver supports both `POST` and `PUT` at `/webhooks/momopay`.

Use `/momopay-tester` for UAT. The browser shows standard 10-character `PMT` references. MTN requires a UUID v4 in `X-Reference-Id`; the gateway generates and stores that provider ID internally, then resolves it automatically for status checks made with the PMT reference.

The local API contract is at `/api/v1/momopay/openapi.json` and requires a dashboard-issued `X-API-Key`.
