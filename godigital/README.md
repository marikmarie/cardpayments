# GoDigital SDK

`GoDigital\Sdk\GoDigitalClient` is a small, stateless client for the GoDigital Tanzania API. It sends API requests and returns GoDigital's JSON response exactly as received.

It does **not** write to a database, manage a wallet, keep a transaction log, deduplicate callbacks, schedule retries, or decide whether a payment succeeded. Collecto should keep those responsibilities in its own journey.

## Add it to Collecto

Copy these two files into Collecto and register the `GoDigital\` namespace in its autoloader:

```text
godigital/src/GoDigitalException.php
godigital/src/Sdk/GoDigitalClient.php
```

If Collecto does not use Composer, load the two files before constructing the client:

```php
require_once __DIR__ . '/GoDigitalException.php';
require_once __DIR__ . '/Sdk/GoDigitalClient.php';

use GoDigital\Sdk\GoDigitalClient;

$goDigital = new GoDigitalClient(
    baseUrl: $_ENV['GODIGITAL_BASE_URL'],
    clientId: $_ENV['GODIGITAL_CLIENT_ID'],
    clientSecret: $_ENV['GODIGITAL_CLIENT_SECRET'],
    merchantId: $_ENV['GODIGITAL_MERCHANT_ID'],
);
```

The four values above remain server-side. The SDK has no `.env` dependency: pass values from Collecto's own configuration service or secret store.

## Collections and payouts

Collecto provides the identifier, callback URL, and all payment data. New `reference`, `request_id`, and `idempotency_key` values must be `PMT` followed by seven uppercase letters or numbers, for example `PMT1234567`.

```php
$response = $goDigital->collect([
    'reference' => 'PMT1234567',
    'request_id' => 'PMT1234567',
    'idempotency_key' => 'PMT1234567',
    'amount' => '10000.00',
    'currency' => 'TZS',
    'provider_code' => 'VODACOM',
    'msisdn' => '255766271010',
    'narration' => 'Collecto wallet top-up',
    'callback_url' => 'https://collecto.example/webhooks/godigital',
]);

// Persist $response and decide the next Collecto journey step in Collecto.
```

Use `$goDigital->disburse([...])` with the same fields for a B2C payout. A successful HTTP response means the request reached GoDigital; use its callback or `status()` response before treating an asynchronous payment as final.

## Other calls

```php
$token = $goDigital->token();
$status = $goDigital->status('PMT1234567');
$balance = $goDigital->walletBalance();
$name = $goDigital->nameCheck('VODACOM', '255766271010');
```

## Callbacks

Collecto exposes the HTTP endpoint and makes its own idempotency decision. It can validate a received callback without writing it anywhere:

```php
$payload = GoDigitalClient::verifyCallback(
    rawBody: file_get_contents('php://input'),
    headers: getallheaders(),
    callbackSecret: $_ENV['GODIGITAL_CALLBACK_SECRET'] ?? null,
);

// Store or process $payload in Collecto, then send its acknowledgement.
```

`verifyCallback()` checks the optional `X-Callback-Content-SHA256` header and validates `X-Callback-Signature` when a callback secret is supplied. It never stores, logs, or acknowledges a callback itself.

## Environment values for the dashboard test screen

The dashboard test screen remains a temporary manual client. It reads its own configuration from `.env`:

```ini
GODIGITAL_BASE_URL="https://..."
GODIGITAL_CLIENT_ID="..."
GODIGITAL_CLIENT_SECRET="..."
GODIGITAL_MERCHANT_ID="..."
GODIGITAL_CALLBACK_URL="https://your-public-domain/webhooks/godigital"
GODIGITAL_CALLBACK_SECRET=""
GODIGITAL_TIMEOUT_SECONDS="30"
```

The dashboard does not keep GoDigital requests, provider responses, callback payloads, or wallet records.
