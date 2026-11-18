# Card SDK

`CissyTech\Cards\Sdk\CyberSourceClient` is a stateless CyberSource Invoicing client. It signs the provider request and returns CyberSource's JSON response. It has no `.env` dependency and never writes logs, invoice records, wallet records, or webhook records.

## Share these files

```text
card/sdk/CyberSourceClient.php
card/README.md
```

The packaged version is `card/card-sdk.zip`.

## Use it in Collecto

```php
require_once __DIR__ . '/CyberSourceClient.php';

use CissyTech\Cards\Sdk\CyberSourceClient;

$cards = new CyberSourceClient(
    environment: $_ENV['CYBERSOURCE_ENV'], // sandbox or live
    merchantId: $_ENV['CYBERSOURCE_MERCHANT_ID'],
    keyId: $_ENV['CYBERSOURCE_KEY_ID'],
    sharedSecret: $_ENV['CYBERSOURCE_SHARED_SECRET'],
);

$invoice = $cards->createInvoice([
    'invoice_number' => 'PMT1234567',
    'amount' => '25000.00',
    'currency' => 'UGX',
    'description' => 'Collecto wallet top-up',
    'customer_name' => 'Customer name',
    'customer_email' => 'customer@example.com',
    'due_date' => '2026-12-31',
]);
```

Use `$cards->sendInvoice($invoiceId)` to ask CyberSource to email an invoice, and `$cards->invoice($invoiceId)` to fetch its provider status. Collecto stores the invoice ID, payment URL, provider response, and all business state in its own system.

## Webhooks

Collecto exposes and acknowledges its own webhook endpoint. Validate the received signature before processing the event:

```php
$valid = CyberSourceClient::verifyWebhook(
    rawBody: file_get_contents('php://input'),
    signatureHeader: $_SERVER['HTTP_V_C_SIGNATURE'] ?? '',
    expectedKeyId: $_ENV['CYBERSOURCE_WEBHOOK_KEY_ID'],
    base64Secret: $_ENV['CYBERSOURCE_WEBHOOK_SHARED_SECRET'],
);
```

The SDK only returns `true` or `false`; duplicate handling, storage, acknowledgement, and wallet changes remain in Collecto.

## Credentials to provide securely

```ini
CYBERSOURCE_ENV="sandbox" # or live
CYBERSOURCE_MERCHANT_ID="..."
CYBERSOURCE_KEY_ID="..."
CYBERSOURCE_SHARED_SECRET="..."
CYBERSOURCE_WEBHOOK_KEY_ID="..."
CYBERSOURCE_WEBHOOK_SHARED_SECRET="..."
```

Use keys made for the selected environment and MID. CyberSource sandbox and live credentials are separate.
