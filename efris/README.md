# CissyTech EFRIS Gateway

This folder is intentionally separate from the card-payment integration. It is the vendor-facing layer for POS and ERP systems that need a single CissyTech API while each company keeps its own URA identity, branches, devices and crypto material.

## What is implemented

- An API key resolves to exactly one tenant, then to one of that tenant's registered branches. A caller cannot supply or override the seller TIN, URA device, certificate, AES key or another tenant.
- `POST /api/v1/efris/invoices` requires `Idempotency-Key` and safely records a request once per tenant/reference.
- `GET /api/v1/efris/invoices/{external_reference}` only returns the caller's own tenant record.
- `GET /api/v1/efris/branches` returns the caller's own configured branches.
- `GET /api/v1/efris/health` is public and does not disclose any tenant information.
- `openapi.json` is the machine-readable API contract. It is also served at `/api/v1/efris/openapi.json`.

## Important boundary

`EFRIS_MODE="mock"` is the default. It accepts and stores test requests but **does not send anything to URA and does not create a fiscal document**. The response intentionally says `TEST_ACCEPTED` and `NOT_SUBMITTED`.

Do not switch the mode to live until the URA test setup is complete and the current official Interface Design has been used to implement and approve the exact encrypted/signed envelope for T109 and its companion interfaces. The provided technical brief is a CissyTech architecture document; its proposed public paths are not URA message definitions.

## Test the gateway locally

1. Start the application and open `http://localhost:8000/links`.
2. Create a dashboard API key. Copy the secret token immediately (it is shown once) and copy the **Integration ID** from the API-key table.
3. Onboard a test tenant and branch. In mock mode you can use simple demo identifiers; this stores identifiers only. Do not pass private keys or shared AES keys to this command.

   ```powershell
   php efris/bin/onboard.php <api-key-id> pilot-company "Pilot Company Ltd" <test-tin> KAMPALA-01 <ura-branch-id> <ura-device-number>
   ```

4. Send the request in `fixtures/invoice.json` with the API key secret from step 2:

   ```powershell
   curl.exe -X POST http://localhost:8000/api/v1/efris/invoices `
     -H "Content-Type: application/json" `
     -H "X-API-Key: <api-key-secret>" `
     -H "Idempotency-Key: POS-INV-000172" `
     --data-binary "@efris/fixtures/invoice.json"
   ```

5. Expect `TEST_ACCEPTED` and `NOT_SUBMITTED`. Repeat the same request: it returns the original local record with `meta.replayed: true`; it must not create a duplicate.
6. Retrieve it using `GET /api/v1/efris/invoices/POS-INV-000172` with the same API key.

## What URA must provide before a real test submission

- A registered pilot taxpayer in URA's **test** environment, its test TIN, approved branches and registered device(s).
- The approved certificate/thumbprint and a secure private-key location for each device.
- The test API connection details and the current URA Interface Design version, including exact envelope, encryption, signing, decryption and response-validation rules.
- A completed key exchange where required (T104), current dictionaries (T115), product/service mappings (T130/T127) and any stock setup before invoice tests.
- URA's test cases, UAT readiness checklist, joint UAT and software-integrator/taxpayer approval before production.

Keep private keys and AES keys in a secrets manager or protected operating-system store. Record only a secret reference in application configuration; never place them in this repository, `storage/`, logs or API requests.
