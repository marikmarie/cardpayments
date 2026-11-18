# Pegasus SDK

The Pegasus package is split into two independent, stateless clients:

- `CissyTech\Pegasus\Sdk\PegasusApiClient` for PegPay Payments and Collections API recipient validation, collections, payouts, transaction status, and balance.
- `CissyTech\Pegasus\Sdk\PegasusWebClient` for hosted PegPay Web card forms, `QueryStatus.aspx`, and signed response validation.

Neither client reads `.env`, stores a transaction, writes a log, throttles calls, retries a request, or updates a wallet. Collecto supplies the values and owns that journey.

## Share these files

```text
pegasus/sdk/PegasusApiClient.php
pegasus/sdk/PegasusWebClient.php
pegasus/README.md
```

The packaged version is `pegasus/pegasus-sdk.zip`.

## Payments and Collections API

```php
require_once __DIR__ . '/PegasusApiClient.php';

use CissyTech\Pegasus\Sdk\PegasusApiClient;

$pegasus = new PegasusApiClient(
    apiUrl: $_ENV['PEGASUS_API_URL'],
    vendorCode: $_ENV['PEGASUS_VENDOR_CODE'],
    password: $_ENV['PEGASUS_PASSWORD'],
    privateKey: file_get_contents($_ENV['PEGASUS_PRIVATE_KEY_PATH']),
    privateKeyPassphrase: $_ENV['PEGASUS_PRIVATE_KEY_PASSPHRASE'] ?? '',
    clientIp: $_ENV['PEGASUS_CLIENT_IP'] ?? '',
);

$response = $pegasus->transaction([
    'transaction_type' => 'PULL',
    'vendor_transaction_id' => 'PMT1234567',
    'amount' => '500',
    'from_account' => '256702685176',
    'from_network' => 'AIRTEL',
    'customer_name' => 'Collecto customer',
    'customer_reference' => 'Collecto wallet top-up',
    'narration' => 'Wallet top-up',
]);
```

Use `transaction_status('PMT1234567')`, `balance()`, and `validateRecipient($account, $network)` for the remaining PegPay API calls. New IDs must be `PMT` followed by seven uppercase letters or digits.

## PegPay Web cards

```php
require_once __DIR__ . '/PegasusWebClient.php';

use CissyTech\Pegasus\Sdk\PegasusWebClient;

$cards = new PegasusWebClient(
    gatewayUrl: $_ENV['PEGASUS_WEB_GATEWAY_URL'],
    statusUrl: $_ENV['PEGASUS_WEB_STATUS_URL'],
    vendorCode: $_ENV['PEGASUS_WEB_VENDOR_CODE'],
    password: $_ENV['PEGASUS_WEB_PASSWORD'],
    secretCode: $_ENV['PEGASUS_WEB_SECRET_CODE'],
    merchantCode: $_ENV['PEGASUS_WEB_MERCHANT_CODE'],
);

$form = $cards->checkoutForm([
    'vendor_transaction_id' => 'PMT1234567',
    'amount' => '25000.00',
    'currency' => 'UGX',
    'description' => 'Collecto wallet top-up',
    'return_url' => 'https://collecto.example/payments/pegasus/return',
]);
// Render an HTML POST form with $form['url'] and $form['fields'] server-side.
```

To query a card collection, Collecto calls `$cards->queryStatus('PMT1234567')`, then validates it with `$cards->verifyResponse($response, 'PMT1234567')`. If PegPay returns `PENDING`, Collecto must wait at least five seconds before another query.

## Credentials to provide securely

```ini
PEGASUS_API_URL="..."
PEGASUS_VENDOR_CODE="..."
PEGASUS_PASSWORD="..."
PEGASUS_PRIVATE_KEY_PATH="..."
PEGASUS_PRIVATE_KEY_PASSPHRASE=""
PEGASUS_CLIENT_IP=""

PEGASUS_WEB_GATEWAY_URL="..."
PEGASUS_WEB_STATUS_URL=".../QueryStatus.aspx"
PEGASUS_WEB_VENDOR_CODE="..."
PEGASUS_WEB_PASSWORD="..."
PEGASUS_WEB_SECRET_CODE="..."
PEGASUS_WEB_MERCHANT_CODE="..."
```

The private key and all credentials must remain on Collecto's server. Never send them to a browser or mobile app.
