<?php
declare(strict_types=1);

namespace Pegasus;

use App\Config;
use App\Store;

/** Hosted PegPay Web card-collection form and signed browser-return handling. */
final class PegasusWebGateway
{
    private PegasusCardLogger $logger;

    public function __construct(private Store $store)
    {
        $this->logger = new PegasusCardLogger($store);
    }

    public function configured(): bool
    {
        foreach (['PEGASUS_WEB_GATEWAY_URL', 'PEGASUS_WEB_VENDOR_CODE', 'PEGASUS_WEB_PASSWORD', 'PEGASUS_WEB_SECRET_CODE', 'PEGASUS_WEB_MERCHANT_CODE'] as $key) {
            if (trim((string) Config::get($key, '')) === '') return false;
        }
        return true;
    }

    /** Create a local collection before redirecting the customer's browser to PegPay. */
    public function prepare(array $input): array
    {
        $this->requireConfiguration();
        $amount = $this->amount($input['amount'] ?? '');
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'UGX')));
        if (!in_array($currency, ['UGX', 'USD'], true)) throw new \InvalidArgumentException('Currency must be UGX or USD.');
        $description = $this->text($input['description'] ?? '', 200, 'Description');
        if ($description === '') throw new \InvalidArgumentException('Enter what the customer is paying for.');
        $name = $this->text($input['customer_name'] ?? '', 120, 'Customer name');
        $email = trim((string) ($input['customer_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid customer email address.');

        $record = [
            'id' => 'CARD-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3))),
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'customer_name' => $name,
            'customer_email' => $email,
            'return_url' => $this->returnUrl(),
            'source_ip' => $this->logger->sourceIp(),
            'status' => 'PENDING',
            'created_at' => gmdate('c'),
        ];
        $this->store->transaction(function (array &$state) use ($record): void {
            $state['pegasus_card_collections'][$record['id']] = $record;
        });
        $this->logger->checkoutCreated($record);
        return $record;
    }

    /** Fields required by the PegPay Web HTML form. Card details are never included. */
    public function formFields(array $collection): array
    {
        $config = $this->config();
        $signature = $this->sign(
            $config['vendor_code'] . $config['merchant_code'] . $collection['amount']
            . $collection['description'] . $collection['currency'] . $collection['return_url'] . $collection['id']
        );
        $fields = [
            'VENDORCODE' => $config['vendor_code'],
            'PASSWORD' => $this->sign($config['password']),
            'VENDOR_TRANID' => $collection['id'],
            'ITEM_TOTAL' => $collection['amount'],
            'ITEM_DESCRIPTION' => $collection['description'],
            'CURRENCY' => $collection['currency'],
            'RETURN_URL' => $collection['return_url'],
            'DIGITAL_SIGNATURE' => $signature,
            'MERCHANTCODE' => $config['merchant_code'],
        ];
        if ($collection['customer_email'] !== '') $fields['EMAILADDRESS'] = $collection['customer_email'];
        if ($collection['customer_name'] !== '') $fields['NAME'] = $collection['customer_name'];
        $this->logger->redirected($collection, $config['gateway_url'], $fields);
        return $fields;
    }

    /** Verify the signed browser result and update its local collection record. */
    public function receive(array $input): array
    {
        $status = strtoupper(trim($this->responseValue($input, ['Status', 'STATUS'])));
        $reason = trim($this->responseValue($input, ['Reason', 'REASON']));
        $vendorId = trim($this->responseValue($input, ['VendorID', 'VendorTranId', 'VENDOR_TRANID']));
        $transactionId = trim($this->responseValue($input, ['TransactionId', 'TranID', 'TRANSACTION_ID']));
        $signature = trim($this->responseValue($input, ['DigitalSignature', 'DIGITAL_SIGNATURE']));
        $valid = $status !== '' && $reason !== '' && $vendorId !== '' && $signature !== ''
            && hash_equals($this->sign($status . $reason . $vendorId), strtolower($signature));
        $record = $vendorId === '' ? null : $this->find($vendorId);
        $this->logger->returned([
            'source_ip' => $this->logger->sourceIp(),
            'gateway_status' => $status,
            'gateway_reason' => $reason,
            'vendor_transaction_id' => $vendorId,
            'pegpay_transaction_id' => $transactionId,
            'signature_valid' => $valid,
            'collection_found' => $record !== null,
        ]);
        if ($record) {
            $record = $this->update($vendorId, [
                'status' => $valid ? $this->status($status) : 'UNVERIFIED',
                'gateway_status' => $status,
                'gateway_reason' => $reason,
                'pegpay_transaction_id' => $transactionId,
                'response_signature_valid' => $valid,
                'returned_at' => gmdate('c'),
            ]);
        }
        return compact('record', 'status', 'reason', 'vendorId', 'transactionId', 'valid');
    }

    /** Record a handled card-flow failure without exposing checkout fields or secrets. */
    public function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error($operation, $error);
    }

    /** @return array{writable: bool, entries: list<array>} */
    public function logDetails(): array
    {
        return $this->logger->details();
    }

    /** @return list<array> */
    public function recent(): array
    {
        $rows = array_values($this->store->read('pegasus_card_collections'));
        usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return array_slice($rows, 0, 8);
    }

    public function gatewayUrl(): string
    {
        return $this->config()['gateway_url'];
    }

    private function requireConfiguration(): void
    {
        if (!$this->configured()) throw new \LogicException('PegPay Card Collections is not configured. Add the PegPay Web credentials to .env.');
        $config = $this->config();
        if (!filter_var($config['gateway_url'], FILTER_VALIDATE_URL) || !str_starts_with($config['gateway_url'], 'https://')) {
            throw new \LogicException('PEGASUS_WEB_GATEWAY_URL must be an HTTPS URL.');
        }
    }

    private function config(): array
    {
        return [
            'gateway_url' => trim((string) Config::get('PEGASUS_WEB_GATEWAY_URL', '')),
            'vendor_code' => trim((string) Config::get('PEGASUS_WEB_VENDOR_CODE', '')),
            'password' => (string) Config::get('PEGASUS_WEB_PASSWORD', ''),
            'secret_code' => (string) Config::get('PEGASUS_WEB_SECRET_CODE', ''),
            'merchant_code' => trim((string) Config::get('PEGASUS_WEB_MERCHANT_CODE', '')),
        ];
    }

    private function returnUrl(): string
    {
        $configured = rtrim(trim((string) Config::get('PEGASUS_WEB_RETURN_URL', '')), '/');
        $url = $configured !== '' ? $configured : rtrim((string) Config::get('APP_URL', ''), '/') . '/pegasus-card/return';
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new \LogicException('Set APP_URL or PEGASUS_WEB_RETURN_URL to a complete return URL.');
        return $url;
    }

    private function find(string $id): ?array
    {
        return $this->store->transaction(fn(array $state): ?array => $state['pegasus_card_collections'][$id] ?? null, false);
    }

    private function update(string $id, array $changes): array
    {
        return $this->store->transaction(function (array &$state) use ($id, $changes): array {
            $record = $state['pegasus_card_collections'][$id] ?? null;
            if (!$record) throw new \RuntimeException('Card collection was not found.');
            return $state['pegasus_card_collections'][$id] = array_merge($record, $changes, ['updated_at' => gmdate('c')]);
        });
    }

    private function sign(string $data): string
    {
        if (!preg_match('/^[\x20-\x7E]*$/', $data)) throw new \InvalidArgumentException('PegPay signed fields must contain ASCII characters only.');
        return hash_hmac('sha256', $data, $this->config()['secret_code']);
    }

    private function amount(mixed $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^[1-9]\d*(?:\.\d{1,2})?$/', $value)) throw new \InvalidArgumentException('Amount must be a positive number with at most two decimal places.');
        return $value;
    }

    private function text(mixed $value, int $length, string $field): string
    {
        $value = trim((string) $value);
        if (strlen($value) > $length || !preg_match('/^[\x20-\x7E]*$/', $value)) throw new \InvalidArgumentException("{$field} must contain at most {$length} ASCII characters.");
        return $value;
    }

    private function responseValue(array $input, array $keys): string
    {
        foreach ($keys as $key) if (isset($input[$key])) return (string) $input[$key];
        return '';
    }

    private function status(string $status): string
    {
        return match ($status) {
            'SUCCESS' => 'SUCCESS',
            'FAILED' => 'FAILED',
            default => 'PENDING',
        };
    }
}
