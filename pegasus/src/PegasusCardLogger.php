<?php
declare(strict_types=1);

namespace Pegasus;

use App\Config;
use App\Store;

/** Writes redacted hosted-card checkout events to file and the Pegasus log table. */
final class PegasusCardLogger
{
    private PegasusRepository $repository;

    public function __construct(private Store $store)
    {
        $this->repository = new PegasusRepository($store);
    }

    public function checkoutCreated(array $collection): void
    {
        $this->write('REQUEST', 'CARD_CHECKOUT_CREATED', (string) $collection['id'], '/pegasus-card/checkout', 201, [
            'source_ip' => $collection['source_ip'] ?? null,
            'amount' => $collection['amount'],
            'currency' => $collection['currency'],
            'description' => $collection['description'],
        ]);
    }

    public function redirected(array $collection, string $gatewayUrl, array $formFields): void
    {
        $this->write('REQUEST', 'CARD_CHECKOUT_REDIRECTED', (string) $collection['id'], $gatewayUrl, 302, [
            'source_ip' => $collection['source_ip'] ?? null,
            'form_fields' => $this->redactFormFields($formFields),
        ]);
    }

    public function returned(array $result): void
    {
        $this->write('RESPONSE', 'CARD_CHECKOUT_RETURNED', $result['vendor_transaction_id'] ?? null, '/pegasus-card/return', 200, [
            'source_ip' => $result['source_ip'] ?? null,
            'Status' => $result['gateway_status'] ?? '',
            'StatusDescription' => $result['gateway_reason'] ?? '',
            'PegpayId' => $result['pegpay_transaction_id'] ?? '',
            'signature_valid' => (bool) ($result['signature_valid'] ?? false),
            'collection_found' => (bool) ($result['collection_found'] ?? false),
        ], true);
    }

    public function error(string $operation, \Throwable $error): void
    {
        $this->write('ERROR', $operation, null, '/pegasus-card', 500, [
            'source_ip' => $this->sourceIp(),
            'error_class' => $error::class,
            'error_message' => $error->getMessage(),
        ], false, $error->getMessage());
    }

    /** Use the direct remote peer address; forwarded headers need trusted-proxy configuration. */
    public function sourceIp(): ?string
    {
        $address = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($address, FILTER_VALIDATE_IP) ? $address : null;
    }

    private function write(
        string $type,
        string $operation,
        ?string $vendorTransactionId,
        string $url,
        int $httpStatus,
        array $payload,
        bool $response = false,
        ?string $error = null,
    ): void {
        $data = array_filter([
            'operation' => $operation,
            'vendor_transaction_id' => $vendorTransactionId,
            'method' => $response ? null : 'POST',
            'url' => $url,
            'http_status' => $httpStatus,
            'body' => $response ? null : $payload,
            'response' => $response ? $payload : null,
            'error' => $error,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
        $entry = ['time' => gmdate('c'), 'type' => $type, 'data' => $data];

        try {
            $this->repository->recordLog($entry);
        } catch (\Throwable) {
            error_log('PegPay card database logging unavailable.');
        }

        $directory = $this->logDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('PegPay card log directory could not be created.');
            return;
        }
        if (!is_writable($directory)) @chmod($directory, 0775);
        if (!is_writable($directory)) {
            error_log('PegPay card log directory is not writable.');
            return;
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false || @file_put_contents($directory . '/pegasus-card.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('PegPay card log could not be written.');
        }
    }

    private function logDirectory(): string
    {
        $configured = trim((string) Config::get('PEGASUS_CARD_LOG_DIRECTORY', ''));
        if ($configured === '') return dirname(__DIR__, 2) . '/storage';
        if (str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)) return $configured;
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($configured, './\\');
    }

    /** Retain the UAT-relevant handoff fields while excluding credentials, signatures, and customer data. */
    private function redactFormFields(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (in_array(strtoupper((string) $key), ['PASSWORD', 'DIGITAL_SIGNATURE', 'EMAILADDRESS', 'NAME'], true)) {
                $fields[$key] = '[redacted]';
                continue;
            }
            $fields[$key] = is_array($value) ? $this->redactFormFields($value) : $value;
        }
        return $fields;
    }
}
