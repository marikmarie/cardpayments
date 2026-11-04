<?php
declare(strict_types=1);

namespace CissyTech\Cards\Sdk;

/**
 * Stateless CyberSource Invoicing client for a host application's own payment journey.
 *
 * It sends signed requests and returns CyberSource JSON. It never creates a
 * database record, writes a log, sends a webhook acknowledgement, or decides
 * how an invoice should affect a wallet or order.
 */
final class CyberSourceClient
{
    private const HOSTS = [
        'sandbox' => 'apitest.cybersource.com',
        'live' => 'api.cybersource.com',
    ];

    private string $host;
    private string $merchantId;
    private string $keyId;
    private string $sharedSecret;
    private int $timeoutSeconds;

    /** @var null|callable(string, string, array<int, string>, string, int): array{status: int, body: string, headers?: array<string, string>} */
    private $transport;

    /**
     * @param null|callable(string, string, array<int, string>, string, int): array{status: int, body: string, headers?: array<string, string>} $transport
     */
    public function __construct(
        string $environment,
        string $merchantId,
        string $keyId,
        string $sharedSecret,
        int $timeoutSeconds = 30,
        ?callable $transport = null,
    ) {
        $environment = strtolower(trim($environment));
        if ($environment === 'production') $environment = 'live';
        if (!isset(self::HOSTS[$environment])) {
            throw new \InvalidArgumentException("environment must be 'sandbox' or 'live'.");
        }

        $this->host = self::HOSTS[$environment];
        $this->merchantId = trim($merchantId);
        $this->keyId = trim($keyId);
        $this->sharedSecret = trim($sharedSecret);
        $this->timeoutSeconds = max(5, $timeoutSeconds);
        $this->transport = $transport;

        foreach (['merchantId' => $this->merchantId, 'keyId' => $this->keyId, 'sharedSecret' => $this->sharedSecret] as $field => $value) {
            if ($value === '') throw new \InvalidArgumentException("CyberSource {$field} is required.");
        }
        if (base64_decode($this->sharedSecret, true) === false) {
            throw new \InvalidArgumentException('sharedSecret must be a Base64-encoded CyberSource shared secret.');
        }
    }

    /** Create a hosted CyberSource invoice and return CyberSource's response JSON. */
    public function createInvoice(array $parameters): array
    {
        $invoiceNumber = $this->required($parameters, ['invoice_number', 'invoiceNumber'], 'invoice_number');
        $amount = $this->amount($parameters['amount'] ?? '');
        $currency = $this->currency($parameters['currency'] ?? '');
        $email = trim((string) ($parameters['customer_email'] ?? $parameters['customerEmail'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('customer_email must be a valid email address when supplied.');
        }

        $payload = [
            'customerInformation' => $this->prune([
                'name' => trim((string) ($parameters['customer_name'] ?? $parameters['customerName'] ?? '')) ?: null,
                'email' => $email ?: null,
            ]),
            'invoiceInformation' => $this->prune([
                'invoiceNumber' => $invoiceNumber,
                'description' => trim((string) ($parameters['description'] ?? '')) ?: null,
                'dueDate' => trim((string) ($parameters['due_date'] ?? $parameters['dueDate'] ?? '')) ?: null,
                'allowPartialPayments' => !empty($parameters['allow_partial'] ?? $parameters['allowPartial'] ?? false),
                'deliveryMode' => 'none',
            ]),
            'orderInformation' => [
                'amountDetails' => ['totalAmount' => $amount, 'currency' => $currency],
            ],
        ];

        return $this->request('POST', '/invoicing/v2/invoices', $payload);
    }

    /** Send an existing CyberSource invoice by email. */
    public function sendInvoice(string $invoiceId): array
    {
        return $this->request('POST', '/invoicing/v2/invoices/' . rawurlencode($this->id($invoiceId)) . '/delivery', new \stdClass());
    }

    /** Fetch an invoice's current provider status. */
    public function invoice(string $invoiceId): array
    {
        return $this->request('GET', '/invoicing/v2/invoices/' . rawurlencode($this->id($invoiceId)));
    }

    /** Validate a CyberSource webhook signature without storing or acknowledging the event. */
    public static function verifyWebhook(string $rawBody, string $signatureHeader, string $expectedKeyId, string $base64Secret, int $toleranceSeconds = 300): bool
    {
        $parts = [];
        foreach (explode(';', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) $parts[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
        }
        if (!isset($parts['t'], $parts['keyId'], $parts['sig']) || !ctype_digit($parts['t'])) return false;
        if (!hash_equals($expectedKeyId, $parts['keyId'])) return false;

        $timestamp = (int) $parts['t'];
        $seconds = $timestamp > 9999999999 ? intdiv($timestamp, 1000) : $timestamp;
        if (abs(time() - $seconds) > $toleranceSeconds) return false;

        $secret = base64_decode($base64Secret, true);
        $received = base64_decode($parts['sig'], true);
        if ($secret === false || $received === false) return false;
        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret, true), $received);
    }

    private function request(string $method, string $path, mixed $payload = null): array
    {
        $method = strtoupper($method);
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $body = $hasBody ? json_encode($payload ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';
        $headers = $this->headers($method, $path, $body, $hasBody);
        $url = 'https://' . $this->host . $path;

        try {
            $response = $this->transport
                ? ($this->transport)($method, $url, $headers, $body, $this->timeoutSeconds)
                : $this->curl($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('CyberSource connection failed: ' . $e->getMessage(), 0, $e);
        }

        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('CyberSource returned a non-JSON response (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            $error = is_array($decoded['errorInformation'] ?? null) ? $decoded['errorInformation'] : [];
            $message = (string) ($decoded['message'] ?? $error['message'] ?? $decoded['reason'] ?? 'CyberSource rejected the request.');
            throw new \RuntimeException($message . ' (HTTP ' . $status . ').');
        }

        return $decoded;
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function curl(string $method, string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL is required for CyberSource.');
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $separator = strpos($header, ':');
                if ($separator !== false) {
                    $responseHeaders[strtolower(trim(substr($header, 0, $separator)))] = trim(substr($header, $separator + 1));
                }
                return strlen($header);
            },
        ]);
        if ($body !== '') curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($raw === false) throw new \RuntimeException($error ?: 'unknown cURL error.');
        return ['status' => $status, 'body' => $raw, 'headers' => $responseHeaders];
    }

    /** Build CyberSource's HTTP-signature headers for one request. */
    private function headers(string $method, string $path, string $body, bool $hasBody): array
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $signed = ['host', 'v-c-date', 'request-target'];
        $values = ['host' => $this->host, 'v-c-date' => $date, 'request-target' => strtolower($method) . ' ' . $path];
        if ($hasBody) {
            $values['digest'] = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
            $signed[] = 'digest';
        }
        $values['v-c-merchant-id'] = $this->merchantId;
        $signed[] = 'v-c-merchant-id';
        $lines = [];
        foreach ($signed as $name) $lines[] = $name . ': ' . $values[$name];
        $secret = base64_decode($this->sharedSecret, true);
        $signature = base64_encode(hash_hmac('sha256', implode("\n", $lines), $secret, true));
        $signatureHeader = sprintf('keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"', $this->keyId, implode(' ', $signed), $signature);

        $headers = ['Host: ' . $this->host, 'Signature: ' . $signatureHeader];
        if ($hasBody) $headers[] = 'Digest: ' . $values['digest'];
        $headers[] = 'v-c-merchant-id: ' . $this->merchantId;
        $headers[] = 'v-c-date: ' . $date;
        $headers[] = 'Content-Type: application/json';
        return $headers;
    }

    private function required(array $parameters, array $keys, string $field): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($parameters[$key] ?? ''));
            if ($value !== '') return $value;
        }
        throw new \InvalidArgumentException("{$field} is required.");
    }

    private function amount(mixed $value): string
    {
        $amount = trim((string) $value);
        if (!preg_match('/^(?:[1-9]\d*)(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('amount must be a positive value with at most two decimal places.');
        }
        return $amount;
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new \InvalidArgumentException('currency must be a three-letter ISO code.');
        return $currency;
    }

    private function id(string $value): string
    {
        $value = trim($value);
        if ($value === '') throw new \InvalidArgumentException('invoiceId is required.');
        return $value;
    }

    private function prune(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->prune($value);
                if ($values[$key] === []) unset($values[$key]);
            } elseif ($value === null || $value === '') {
                unset($values[$key]);
            }
        }
        return $values;
    }
}
