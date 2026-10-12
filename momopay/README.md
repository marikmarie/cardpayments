# MTN MoMo Pay gateway

This module replaces the legacy `momopay.cissytech.com` scripts with one small server-side implementation that matches the existing gateway structure.

It supports the operations supplied in the MTN collection: Collection token, Request to Pay, collection status, collection balance, account-holder active/basic-info checks, Collection v2 withdrawal, Disbursement token, Transfer, transfer status, and disbursement balance. Credentials never leave the server, and every outbound request, response, and callback is logged after masking secrets and customer numbers.

## Configure

Provision a Collection API user/key and a Disbursement API user/key in the MTN MoMo portal. An API user is the UUID v4 you create with MTN; it is not your MTN account email, merchant code, or subscription key. Use the API key issued for that exact UUID and the matching product subscription key, then set only these values in `.env`:

```ini
MOMOPAY_BASE_URL="https://sandbox.momodeveloper.mtn.com"
MOMOPAY_TARGET_ENVIRONMENT="sandbox"
MOMOPAY_DEFAULT_CURRENCY="UGX"
MOMOPAY_PROVISIONING_SUBSCRIPTION_KEY=""
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

1. In the MTN developer portal, create the developer account, subscribe to **Collections**, and copy its Primary or Secondary sandbox subscription key. Do the same for **Disbursements** when payouts are required. Set the key used for sandbox user creation in `MOMOPAY_PROVISIONING_SUBSCRIPTION_KEY`; keep each product's own key in its Collection or Disbursement variable.
2. In `/momopay-tester`, open **Setup**. Enter the public callback host without a path, select the product, and create an API user. The tester generates a UUID v4 if you leave that field blank. It logs MTN's exact response and displays the UUID to copy into the matching `MOMOPAY_<PRODUCT>_API_USER` variable.
3. In **Setup**, create the API key for the same UUID. The MTN key is shown once for copying into the matching `MOMOPAY_<PRODUCT>_API_KEY` variable; it is never written to Logs. You can also run the two Provisioning API calls directly:

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

4. If you use the direct request instead of Setup, create an API key for the same API user. Save the returned key immediately; MTN does not return an existing API key again.

   ```powershell
   $apiKeyResult = Invoke-RestMethod -Method Post -Uri "$provisioningBase/apiuser/$apiUser/apikey" -Headers @{ 'Ocp-Apim-Subscription-Key' = $subscriptionKey }
   $apiKeyResult.apiKey
   ```

5. Put the three Collection values into `MOMOPAY_COLLECTION_SUBSCRIPTION_KEY`, `MOMOPAY_COLLECTION_API_USER`, and `MOMOPAY_COLLECTION_API_KEY`. For a Collection token test, those values must all come from the same sandbox setup. Do the equivalent for Disbursements, restart the app after changing `.env`, then use **Check Collection token** or **Check Disbursement token** in `/momopay-tester`.

`login_failed` means MTN reached the token endpoint but rejected the Basic-auth API user/API key pair. Create a new API key for the configured API user, or create a new API user and key together, then update both variables. Do not substitute the subscription key for either value.

For production, replace the base URL, target environment, and every product credential with the production values managed in the MTN Partner Portal after onboarding. Register the public callback host with MTN; the receiver supports both `POST` and `PUT` at `/webhooks/momopay`. MTN documents HTTP callbacks for sandbox and HTTPS callbacks for production; the callback URL must belong to the host registered with the API user.

Use `/momopay-tester` for UAT. The browser shows standard 10-character `PMT` references. MTN requires a UUID v4 in `X-Reference-Id`; the gateway generates and stores that provider ID internally, then resolves it automatically for status checks made with the PMT reference.

The local API contract is at `/api/v1/momopay/openapi.json` and requires a dashboard-issued `X-API-Key`.

## SIT workbook test guide

The **SIT cases** tab in `/momopay-tester` covers the automated API cases in `UG Momo OpenAPI SIT Sheet.xlsx`. It is deliberately available only while `MOMOPAY_TARGET_ENVIRONMENT=sandbox`, so a negative test cannot send an intentionally bad credential, target environment, or request to production.

Select the matching product and one case, complete only the fields that case needs, and submit it. The response screen gives the generated MTN request UUID where one is created. Open **Logs** after every test: the outbound request and the provider response have a `[SIT: …]` label. Every response entry records `source: MTN MoMo API`, the actual HTTP status, and the raw body returned by MTN. Credentials, bearer tokens, and customer numbers are masked; no response body or transaction outcome is invented by this application. Record the HTTP status, response body, and any final callback or status result in the SIT sheet.

### Workbook coverage

| Workbook sheet | UAT coverage in this project |
| --- | --- |
| SignUp & Provision API User | Portal signup and product subscription remain MTN portal steps. The **Setup** tab runs the sandbox API-user and API-key calls and logs MTN's returned bodies. |
| Collection | Collection OAuth, RequestToPay, status, callback-protocol, validation, and negative-header cases are in **SIT cases** with **Collection** selected. |
| Disbursement and Remittance | Disbursement OAuth, Transfer, status, callback-protocol, validation, and negative-header cases are in **SIT cases** with **Disbursement** selected. |
| ValidateAccount | Active and basic-user-information checks for either product are in **Account** and the ValidateAccount group in **SIT cases**. |
| Balance | Collection and Disbursement balance checks plus authentication/header tests are in **Account** and the Balance group in **SIT cases**. The two worksheet rows that specify account-holder type or ID cannot be sent because MTN's balance endpoint has neither parameter. |
| FAQ and documentation sheets | The setup, callback, environment, currency, and final-status requirements below come from these workbook sheets. |

### Required UAT setup from the FAQ

1. The MTN API endpoint remains HTTPS in every environment. For the callback URL, use a public **HTTP** URL in sandbox and a public **HTTPS** URL in production. During API-user creation, `providerCallbackHost` is only the host name, such as `payments.example.com`; do not include a path.
2. Sandbox does not move real funds or issue customer prompts. Treat a request's initial HTTP response as submission only, then record MTN's callback or status response as the final result.
3. Use a separate API user, API key, and subscription key for every country and product setup. Production API users and API keys come from the MTN Partner Portal, not the sandbox Provisioning API.
4. Keep the MTN UUID v4 returned or sent in `X-Reference-Id`. The tester displays it beside the local PMT reference so it can be pasted into the status and duplicate-reference cases. The MTN API does not provide a search for unrelated historic transactions.
5. For production, set the currency and target environment issued for that country. The workbook lists the following values:

| Country | Currency | `MOMOPAY_TARGET_ENVIRONMENT` |
| --- | --- | --- |
| Uganda | `UGX` | `mtnuganda` |
| Ghana | `GHS` | `mtnghana` |
| Ivory Coast | `XOF` | `mtnivorycoast` |
| Zambia | `ZMW` | `mtnzambia` |
| Cameroon | `XAF` | `mtncameroon` |
| Benin | `XOF` | `mtnbenin` |
| Congo | `XAF` | `mtncongo` |
| Eswatini | `SZL` | `mtnswaziland` |
| Guinea-Conakry | `GNF` | `mtnguineaconakry` |
| South Africa | `ZAR` | `mtnsouthafrica` |
| Liberia | `LRD` | `mtnliberia` |

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
| TC02-01 — approved payment | **Successful request** | An initial HTTP 202 only confirms MTN accepted the request. Record `SUCCESS` only when it appears in MTN's callback or status-query response. |
| TC02-02 — rejected payment | **Subscriber rejects** | The initial HTTP response only records submission. Record the final rejected/failed status from MTN's callback or status query. |
| TC02-03 — timeout | **Approval timeout** | Record MTN's final timeout/failed response from the callback or status query. |
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
| TC02-01 — normal transfer | **Successful request** | An initial HTTP 202 only confirms MTN accepted the request. Record final `SUCCESS` only from MTN's callback or status-query response. |
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
| TC02-02 — invalid account-holder type | Not sent to the tester | Mark this row not applicable. The MTN balance endpoint does not accept an account-holder type, so there is no MoMo API request or response to log. |
| TC02-03 — invalid account-holder ID | Not sent to the tester | Mark this row not applicable. The MTN balance endpoint does not accept an account-holder ID, so there is no MoMo API request or response to log. |
| TC02-04 — invalid subscription key | **Invalid subscription key** | Balance request is rejected with an authentication/subscription failure. |
| TC02-05 — invalid target environment | **Invalid target environment** | Balance request is rejected because the target header is invalid. |
| TC02-06 — invalid bearer token | **Invalid bearer token** | Balance request is rejected with an authentication failure. |

### Interpreting results

The sandbox accepts a request before a customer approval, rejection, timeout, available-funds, or limit outcome is final. An initial HTTP 202 is not a pass or fail by itself: wait for MTN's callback or run the matching **Valid MTN UUID** status case and record the final response. The tester logs every request and response it sends or receives; the provider controls the final sandbox behavior and the exact body or status for a negative test can differ by MTN configuration.
