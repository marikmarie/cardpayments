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

## SIT workbook test guide

The **SIT cases** tab in `/momopay-tester` covers the automated API cases in `UG Momo OpenAPI SIT Sheet.xlsx`. It is deliberately available only while `MOMOPAY_TARGET_ENVIRONMENT=sandbox`, so a negative test cannot send an intentionally bad credential, target environment, or request to production.

Select the matching product and one case, complete only the fields that case needs, and submit it. The response screen gives the generated MTN request UUID where one is created. Open **Logs** after every test: the outbound request and the provider response have a `[SIT: …]` label, while credentials, bearer tokens, and customer numbers remain masked. Record the HTTP status, response body, and any final callback or status result in the SIT sheet.

### Portal provisioning cases

These rows are performed in the MTN developer portal or Provisioning API before using the tester. They are documented here because the remaining tests cannot work without them.

| Sheet case | Action | Expected evidence |
| --- | --- | --- |
| TC01-01 — sign up | Create or sign in to the MTN developer account. | The developer account is active and can open the sandbox products. |
| TC01-02 — subscribe | Subscribe the application to Collection and, when payouts are required, Disbursement. | A separate subscription key is available for each subscribed product. |
| TC02-01 — create API user | Send `POST /v1_0/apiuser` with a new UUID v4 in `X-Reference-Id` and the registered callback host. | HTTP 201/202 from MTN; save that UUID as the product API user. |
| TC02-02 — create API key | Send `POST /v1_0/apiuser/{apiUser}/apikey` for the same API user. | HTTP 201/202 and one API key returned. Save it immediately; MTN does not reveal an existing key again. |

### OAuth 2.0 cases — Collection, Disbursement, ValidateAccount, and Balance

Choose the appropriate product for each sheet. These are the workbook's TC01-01 through TC01-04 authentication rows.

| Case | Tester selection | Expected result |
| --- | --- | --- |
| TC01-01 — invalid subscription token | **Invalid subscription key** | The token call is rejected. Expect an HTTP authentication/subscription failure and no usable bearer token. |
| TC01-02 — invalid API key | **Invalid API key** | The token call is rejected and no bearer token is issued. MTN may return `login_failed` with HTTP 500 for this sandbox path; the log is the recorded evidence. |
| TC01-03 — valid API key and subscription | **Valid token** | HTTP 200 with a valid token response. The tester confirms the token type and expiry but never displays the token. |
| TC01-04 — second token before expiry | **Second valid token** | A second successful HTTP 200 token response. Compare the two log entries; do not expect the raw token to be displayed. |

### Collection — RequestToPay and status cases

Choose **Collection** in the SIT tab. The request cases send `POST /collection/v1_0/requesttopay`; status cases query `GET /collection/v1_0/requesttopay/{MTN UUID}`.

| Sheet case | Tester selection | Expected result |
| --- | --- | --- |
| TC02-01 — approved payment | **Successful request** | Initial HTTP 202/PENDING is normal. The final status must become `SUCCESS` only after the subscriber approves; retain the callback or status-query log. |
| TC02-02 — rejected payment | **Subscriber rejects** | Initial acceptance can be HTTP 202/PENDING. Record the final rejected/failed status from the callback or status query. |
| TC02-03 — timeout | **Approval timeout** | The request can remain pending first, then reaches MTN's failed/timeout outcome. Record the final callback or status response. |
| TC02-04 — duplicate reference | **Duplicate MTN UUID** | First submit a request, copy its MTN UUID into **MTN UUID**, then run this case. The repeat must be rejected as a duplicate, commonly HTTP 409; no second payment should be created. |
| TC02-05 — incomplete request | **Missing amount** and **Missing currency** | Run both variants. Each omits the named required field and should receive a validation failure, commonly HTTP 400. |
| TC02-06 — insufficient funds | **Insufficient funds** | The request is sent with a deliberately large amount. Record MTN's final insufficient-funds failure; sandbox funding rules determine the exact response. |
| TC02-07 — invalid B party | **Invalid recipient** | The request uses an invalid MSISDN and is rejected by MTN validation or the final transaction result. |
| TC02-08 — daily limit | **Exceeds daily limit** | The request uses a deliberately large amount. Record MTN's final limit failure if the sandbox account enforces a limit. |
| TC02-09 — invalid subscription key | **Invalid subscription key** | The protected request is rejected, normally with an HTTP 401/403 response. |
| TC02-10 — invalid target environment | **Invalid target environment** | The protected request is rejected because `X-Target-Environment` is intentionally invalid. |
| TC02-11 — invalid bearer token | **Invalid bearer token** | The protected request is rejected, normally HTTP 401. |
| TC02-12 — valid transaction status | **Valid MTN UUID** | Paste the MTN UUID from an earlier request. The response returns that transaction's current or final status. |
| TC02-13 — invalid transaction status reference | **Unknown MTN UUID** | The tester generates an unknown UUID. Expect a not-found or validation failure from MTN. |
| TC02-14 — status invalid subscription key | **Invalid subscription key** under Transaction status | The status request is rejected before a transaction result is returned. |
| TC02-15 — status invalid target environment | **Invalid target environment** under Transaction status | The status request is rejected because the target header is invalid. |
| TC02-16 — status invalid bearer token | **Invalid bearer token** under Transaction status | The status request is rejected with an authentication failure. |
| TC02-17 — HTTPS protocol | **HTTPS callback URL** | Enter a public `https://` callback URL. The request log must include that URL. In sandbox, callback delivery still depends on the host registered with MTN. |
| TC02-18 — HTTP protocol | **HTTP callback URL** | Enter a public `http://` callback URL. Sandbox callback testing uses HTTP according to the workbook FAQ; retain the request and callback logs. |

### Disbursement and Remittance — Transfer and status cases

Choose **Disbursement** in the SIT tab. The request cases send `POST /disbursement/v1_0/transfer`; status cases query `GET /disbursement/v1_0/transfer/{MTN UUID}`.

| Sheet case | Tester selection | Expected result |
| --- | --- | --- |
| TC02-01 — normal transfer | **Successful request** | Initial HTTP 202/PENDING is expected. Record final `SUCCESS` from the callback or status query. |
| TC02-02 — duplicate reference | **Duplicate MTN UUID** | Reuse the MTN UUID returned by the first request. The provider rejects the duplicate, commonly HTTP 409, and no second transfer is created. |
| TC02-03 — incomplete request | **Missing amount** and **Missing currency** | Each variant omits one mandatory field and should produce a validation failure. |
| TC02-04 — insufficient funds | **Insufficient funds** | Record MTN's final insufficient-funds outcome; its exact sandbox response depends on the funded test account. |
| TC02-05 — invalid B party | **Invalid recipient** | The invalid recipient MSISDN must be rejected by MTN validation or the final transaction result. |
| TC02-06 — daily limit | **Exceeds daily limit** | Record the limit failure when the sandbox account enforces the configured limit. |
| TC02-07 — invalid subscription key | **Invalid subscription key** | Protected transfer request is rejected, normally HTTP 401/403. |
| TC02-08 — invalid target environment | **Invalid target environment** | Protected transfer request is rejected because the target header is invalid. |
| TC02-09 — invalid bearer token | **Invalid bearer token** | Protected transfer request is rejected with an authentication failure. |
| TC02-10 — valid transfer status | **Valid MTN UUID** | Paste a UUID from an earlier transfer and record its current/final status. |
| TC02-11 — invalid status reference | **Unknown MTN UUID** | Expect a not-found or validation failure. |
| TC02-12 — status invalid subscription key | **Invalid subscription key** under Transaction status | Status request is rejected before a transaction result is returned. |
| TC02-13 — status invalid target environment | **Invalid target environment** under Transaction status | Status request is rejected because the target header is invalid. |
| TC02-14 — status invalid bearer token | **Invalid bearer token** under Transaction status | Status request is rejected with an authentication failure. |
| TC02-15 — HTTPS protocol | **HTTPS callback URL** | The outgoing request log contains the entered public HTTPS callback URL. |
| TC02-16 — HTTP protocol | **HTTP callback URL** | The outgoing request log contains the entered public HTTP callback URL; retain any callback log produced by the sandbox. |

### ValidateAccount cases

Choose either product as required by the test setup, then use the **Account holder** group in the SIT tab. The tester sends the active or basic-user-information query specified in **Account query**.

| Sheet case | Tester selection | Expected result |
| --- | --- | --- |
| TC02-01 — valid account | **Valid account check** | HTTP 200 and the requested active/basic-user-information response for a valid MSISDN. |
| TC02-02 — invalid account-holder type | **Invalid account holder type** | MTN rejects the invalid path parameter with a validation/not-found response. |
| TC02-03 — invalid account-holder ID | **Invalid account holder ID** | MTN rejects the invalid account identifier with a validation/not-found response. |
| TC02-04 — invalid subscription key | **Invalid subscription key** | Request is rejected with an authentication/subscription failure. |
| TC02-05 — invalid target environment | **Invalid target environment** | Request is rejected because the target header is invalid. |
| TC02-06 — invalid bearer token | **Invalid bearer token** | Request is rejected with an authentication failure. |

### Balance cases

Choose the required product, then use the **Balance** group. The implemented MTN balance endpoint is `/collection/v1_0/account/balance` or `/disbursement/v1_0/account/balance`; it has no account-holder type or account-holder ID parameter.

| Sheet case | Tester selection | Expected result |
| --- | --- | --- |
| TC02-01 — valid balance | **Valid balance** | HTTP 200 with the configured account's balance response. |
| TC02-02 — invalid account-holder type | **Invalid account holder type (not applicable)** | The tester writes a `NOT_APPLICABLE` log entry. Mark this row not applicable because the MTN balance endpoint does not accept an account-holder type. |
| TC02-03 — invalid account-holder ID | **Invalid account holder ID (not applicable)** | The tester writes a `NOT_APPLICABLE` log entry. Mark this row not applicable because the MTN balance endpoint does not accept an account-holder ID. |
| TC02-04 — invalid subscription key | **Invalid subscription key** | Balance request is rejected with an authentication/subscription failure. |
| TC02-05 — invalid target environment | **Invalid target environment** | Balance request is rejected because the target header is invalid. |
| TC02-06 — invalid bearer token | **Invalid bearer token** | Balance request is rejected with an authentication failure. |

### Interpreting results

The sandbox accepts a request before a customer approval, rejection, timeout, available-funds, or limit outcome is final. For those scenarios, an initial HTTP 202 and `PENDING` result is not a pass or fail by itself: wait for the callback or run the matching **Valid MTN UUID** status case and record the final response. The tester logs every request and response it sends or receives; the provider controls the final sandbox behavior and the exact body or status for a negative test can differ by MTN configuration.
