# MTN MoMo Pay gateway

Use `/momopay-tester` to run MTN MoMo Collection and Disbursement tests. It keeps credentials on the server and shows the actual request, HTTP status, and MTN response for every test. Secrets, tokens, and customer numbers are masked in the browser and Logs.

## Environment

Add these values to `.env`. Collection and Disbursement each need their own subscription key, API user, and API key.

```ini
MOMOPAY_BASE_URL="https://sandbox.momodeveloper.mtn.com"
MOMOPAY_TARGET_ENVIRONMENT="sandbox"
MOMOPAY_DEFAULT_CURRENCY="EUR"
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

## Set up once

1. In the MTN portal, create a developer account and subscribe to **Collections**. Subscribe to **Disbursements** too if you will test payouts.
2. Open **MoMo Pay → Setup**. Select the product, enter the public callback host without a path, then create the API user. Save the returned UUID in the matching `MOMOPAY_<PRODUCT>_API_USER` value.
3. Create the API key for that same UUID. Copy it immediately into `MOMOPAY_<PRODUCT>_API_KEY`; MTN only returns it once.
4. Add the subscription key for each product, restart the app, and use **Connection** to check the token.

An API user is the UUID created with MTN. It is not your email address, merchant code, or subscription key.

If MTN returns `login_failed`, its token endpoint rejected the API user and API key pair. Create a new key for that API user, or create a new user and key together, then update both `.env` values.

For production, use the credentials, target environment, and currency issued during MTN onboarding. Register the public callback host with MTN. The tester receives callbacks at `/webhooks/momopay`.

## SIT testing

The **SIT cases** tab covers the API cases in `UG Momo OpenAPI SIT Sheet.xlsx`. It is available only in the sandbox so intentional bad credentials and headers cannot be sent to production.

1. Choose the product and the matching SIT case.
2. Read the small **Copy to SIT** note beside the selected case.
3. Run the case, then replace `[status]` and `[transaction status]` in that note with the actual MTN result shown beneath the form or in **Logs**.
4. Paste the completed sentence into the workbook test-result note.

The test runner never creates a result itself. Record the exact MTN response. An initial HTTP `202` means MTN accepted the request; use the callback or transaction-status result for the final payment outcome.

### Copy-ready SIT note examples

| Test type | Short note to paste after replacing the brackets |
| --- | --- |
| Valid token or account/balance check | `MTN returned HTTP [status] for the check. The raw response is in MoMo Logs.` |
| Invalid API key, subscription key, target, or bearer token | `MTN returned HTTP [status] for the intentional header or credential test. The raw response is in MoMo Logs.` |
| Payment or transfer | `MTN returned HTTP [status] for the request. Final result: [callback or status result].` |
| Duplicate reference or missing field | `MTN returned HTTP [status] for the deliberate validation test. The raw response is in MoMo Logs.` |
| Valid transaction status | `MTN returned [transaction status] for the supplied X-Reference-Id. The raw response is in MoMo Logs.` |
| HTTP or HTTPS protocol check | `MTN returned HTTP [status] for the request. The exact URL and response are shown below.` |

Each specific SIT case displays its own version of the note in the tester, including the payment, transaction-status, account, and balance variants.

### Workbook coverage

| Workbook sheet | Where to test it |
| --- | --- |
| SignUp & Provision API User | MTN portal, then **Setup** for API-user and API-key calls. |
| Collection | **SIT cases** with Collection selected. |
| Disbursement and Remittance | **SIT cases** with Disbursement selected. |
| ValidateAccount | **Account** or the Account holder group in **SIT cases**. |
| Balance | **Account** or the Balance group in **SIT cases**. |
| FAQ and documentation | Configuration and callback guidance in this file. |

### Test rules

- The sandbox payment cases use `EUR`. OAuth token calls do not send `X-Reference-Id`; their generated workbook value is only for recording in the sheet.
- A payment request creates an MTN UUID. Save it for duplicate-reference and valid transaction-status tests.
- The HTTP and HTTPS cases change the outgoing MoMo API URL only; they do not change the callback URL.
- The MTN balance endpoint has no account-holder type or ID parameter. Mark the two matching Balance workbook rows as **Not applicable**.

The local API contract is at `/api/v1/momopay/openapi.json` and requires a dashboard-issued `X-API-Key`.
