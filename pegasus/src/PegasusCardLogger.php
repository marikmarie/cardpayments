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
        $this->write('RESPONSE', 'CARD_CHECKOUT_CREATED', (string) $collection['id'], '/pegasus-card/checkout', 201, [
            'source_ip' => $collection['source_ip'] ?? null,
            'outcome' => 'SUCCESS',
            'status' => 'PENDING',
            'amount' => $collection['amount'],
            'currency' => $collection['currency'],
            'description' => $collection['description'],
        ], true);
    }

    public function checkoutReceived(array $input): void
    {
        $this->write('REQUEST', 'CARD_CHECKOUT_RECEIVED', null, '/pegasus-card/checkout', null, $this->checkoutPayload($input));
    }

    public function redirected(array $collection, string $gatewayUrl, array $formFields): void
    {
        $this->write('REQUEST', 'CARD_CHECKOUT_REDIRECTED', (string) $collection['id'], $gatewayUrl, 302, [
            'source_ip' => $collection['source_ip'] ?? null,
            'outcome' => 'PENDING',
            'form_fields' => $this->redactFormFields($formFields),
        ]);
    }

    public function returnReceived(array $input): void
    {
        $payload = $this->returnPayload($input);
        $this->write('REQUEST', 'CARD_RETURN_RECEIVED', ($payload['VendorID'] ?? '') ?: null, '/pegasus-card/return', null, $payload);
    }

    public function returned(array $result): void
    {
        $signatureValid = (bool) ($result['signature_valid'] ?? false);
        $status = strtoupper((string) ($result['gateway_status'] ?? ''));
        $outcome = !$signatureValid ? 'FAILED' : match ($status) {
            'SUCCESS' => 'SUCCESS',
            'FAILED' => 'FAILED',
            default => 'PENDING',
        };
        $this->write('RESPONSE', 'CARD_CHECKOUT_RETURNED', $result['vendor_transaction_id'] ?? null, '/pegasus-card/return', 200, [
            'source_ip' => $result['source_ip'] ?? null,
            'Status' => $status,
            'StatusDescription' => $result['gateway_reason'] ?? '',
            'PegpayId' => $result['pegpay_transaction_id'] ?? '',
            'signature_valid' => $signatureValid,
            'collection_found' => (bool) ($result['collection_found'] ?? false),
            'outcome' => $outcome,
        ], true);
    }

    public function error(string $operation, \Throwable $error, array $input = []): void
    {
        $isReturn = str_contains($operation, 'RETURN');
        $request = $isReturn ? $this->returnPayload($input) : $this->checkoutPayload($input);
        $this->write('RESPONSE', $operation, $request['VendorID'] ?? null, $isReturn ? '/pegasus-card/return' : '/pegasus-card/checkout', $this->failureStatus($error), [
            'source_ip' => $this->sourceIp(),
            'outcome' => 'FAILED',
            'error_class' => $error::class,
            'error_message' => $error->getMessage(),
            'request' => $request,
        ], true, $error->getMessage());
    }

    /** @return array{writable: bool, entries: list<array>} */
    public function details(): array
    {
        $directory = $this->logDirectory();
        $path = $directory . '/pegasus-card.log';
        $entries = [];
        if (is_readable($path)) {
            $size = filesize($path) ?: 0;
            $contents = (string) file_get_contents($path, false, null, max(0, $size - 65536));
            foreach (array_reverse(array_filter(explode(PHP_EOL, $contents))) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) $entries[] = $entry;
                if (count($entries) === 12) break;
            }
        }
        if ($entries === []) $entries = $this->repository->recentCardLogs();
        return ['writable' => is_dir($directory) && is_writable($directory), 'entries' => $entries];
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
        ?int $httpStatus,
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

    private function checkoutPayload(array $input): array
    {
        return array_filter([
            'source_ip' => $this->sourceIp(),
            'amount' => $this->inputValue($input, ['amount']),
            'currency' => $this->inputValue($input, ['currency']),
            'description' => $this->inputValue($input, ['description']),
            'customer_name' => $this->inputValue($input, ['customer_name']) === '' ? null : '[redacted]',
            'customer_email' => $this->inputValue($input, ['customer_email']) === '' ? null : '[redacted]',
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function returnPayload(array $input): array
    {
        $signature = $this->inputValue($input, ['DigitalSignature', 'DIGITAL_SIGNATURE']);
        return array_filter([
            'source_ip' => $this->sourceIp(),
            'Status' => $this->inputValue($input, ['Status', 'STATUS']),
            'Reason' => $this->inputValue($input, ['Reason', 'REASON']),
            'VendorID' => $this->inputValue($input, ['VendorID', 'VendorTranId', 'VENDOR_TRANID']),
            'TransactionId' => $this->inputValue($input, ['TransactionId', 'TranID', 'TRANSACTION_ID']),
            'DigitalSignature' => $signature === '' ? null : '[redacted]',
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function inputValue(array $input, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) return trim((string) $input[$key]);
        }
        return '';
    }

    private function failureStatus(\Throwable $error): int
    {
        return match (true) {
            $error instanceof \InvalidArgumentException => 422,
            $error instanceof \LogicException => 503,
            default => 500,
        };
    }
}
