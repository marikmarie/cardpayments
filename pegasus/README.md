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
    'transaction_type' => $payment['transaction_type'], // PULL or PUSH
    'vendor_transaction_id' => $payment['vendor_transaction_id'],
    'amount' => $payment['amount'],
    'from_account' => $payment['from_account'],
    'from_network' => $payment['from_network'],
    'to_account' => $payment['to_account'] ?? '',
    'to_network' => $payment['to_network'] ?? '',
    'customer_name' => $payment['customer_name'] ?? '',
    'customer_reference' => $payment['customer_reference'] ?? '',
    'narration' => $payment['narration'] ?? '',
]);
```

`$payment` is supplied by Collecto after it validates the customer and creates its own payment record. Use `transaction_status($payment['vendor_transaction_id'])`, `balance()`, and `validateRecipient($account, $network)` for the remaining PegPay API calls. New IDs must be `PMT` followed by seven uppercase letters or digits.

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
    'vendor_transaction_id' => $cardPayment['vendor_transaction_id'],
    'amount' => $cardPayment['amount'],
    'currency' => $cardPayment['currency'],
    'description' => $cardPayment['description'],
    'return_url' => $cardPayment['return_url'],
]);
// Render an HTML POST form with $form['url'] and $form['fields'] server-side.
```

`$cardPayment` is supplied by Collecto after it creates the collection record. To query it, call `$cards->queryStatus($cardPayment['vendor_transaction_id'])`, then validate it with `$cards->verifyResponse($response, $cardPayment['vendor_transaction_id'])`. If PegPay returns `PENDING`, Collecto must wait at least five seconds before another query.

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
