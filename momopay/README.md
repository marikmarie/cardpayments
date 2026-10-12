# MTN MoMo Pay gateway

This module replaces the legacy `momopay.cissytech.com` scripts with one small server-side implementation that matches the existing gateway structure.

It supports the operations supplied in the MTN collection: Collection token, Request to Pay, collection status, collection balance, account-holder active/basic-info checks, Collection v2 withdrawal, Disbursement token, Transfer, transfer status, and disbursement balance. Credentials never leave the server, and every outbound request, response, and callback is logged after masking secrets and customer numbers.

## Configure

Provision a Collection API user/key and a Disbursement API user/key in the MTN MoMo portal. An API user is the UUID v4 you create with MTN; it is not your MTN account email, merchant code, or subscription key. Use the API key issued for that exact UUID and the matching product subscription key, then set only these values in `.env`:

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

## Get sandbox credentials

1. In the MTN developer portal, subscribe to **Collections** and copy its Primary or Secondary subscription key from your profile. Do the same for **Disbursements** only if you will test payouts. Subscription keys are product-specific.
2. Create a new UUID v4. This UUID becomes the API user value. In PowerShell, use `New-Guid` and copy the value exactly, including dashes.
3. Create that API user through MTN's Sandbox Provisioning API, using the Collection subscription key. The callback host must be the host name only, such as `mariam.cissytech.com`.

   ```powershell
   $apiUser = (New-Guid).Guid
   $subscriptionKey = 'YOUR_COLLECTION_SUBSCRIPTION_KEY'
   $provisioningBase = 'https://sandbox.momodeveloper.mtn.com/v1_0'
   $headers = @{
     'Ocp-Apim-Subscription-Key' = $subscriptionKey
     'X-Reference-Id' = $apiUser
   }
   Invoke-RestMethod -Method Post -Uri "$provisioningBase/apiuser" -Headers $headers -ContentType 'application/json' -Body '{"providerCallbackHost":"your-public-host.example"}'
   ```

4. Create an API key for the same API user. Save the returned key immediately; MTN does not return an existing API key again.

   ```powershell
   $apiKeyResult = Invoke-RestMethod -Method Post -Uri "$provisioningBase/apiuser/$apiUser/apikey" -Headers @{ 'Ocp-Apim-Subscription-Key' = $subscriptionKey }
   $apiKeyResult.apiKey
   ```

5. Put the three Collection values into `MOMOPAY_COLLECTION_SUBSCRIPTION_KEY`, `MOMOPAY_COLLECTION_API_USER`, and `MOMOPAY_COLLECTION_API_KEY`. For a Collection token test, those values must all come from the same sandbox setup. Then use **Check Collection token** in `/momopay-tester`.

`login_failed` means MTN reached the token endpoint but rejected the Basic-auth API user/API key pair. Create a new API key for the configured API user, or create a new API user and key together, then update both variables. Do not substitute the subscription key for either value.

For production, replace the base URL, target environment, and every product credential with the production values managed in the MTN Partner Portal after onboarding. Register the public callback host with MTN; the receiver supports both `POST` and `PUT` at `/webhooks/momopay`. MTN documents HTTP callbacks for sandbox and HTTPS callbacks for production; the callback URL must belong to the host registered with the API user.

Use `/momopay-tester` for UAT. The browser shows standard 10-character `PMT` references. MTN requires a UUID v4 in `X-Reference-Id`; the gateway generates and stores that provider ID internally, then resolves it automatically for status checks made with the PMT reference.

The local API contract is at `/api/v1/momopay/openapi.json` and requires a dashboard-issued `X-API-Key`.
