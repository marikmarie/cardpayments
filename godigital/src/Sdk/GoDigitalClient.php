<?php
declare(strict_types=1);

namespace GoDigital\Sdk;

use GoDigital\GoDigitalException;

/**
 * Stateless GoDigital API client.
 *
 * This client deliberately owns no database, wallet, webhook receiver, retry
 * queue, or integration log. The consuming application supplies its own
 * payment reference and chooses how to store, retry, and reconcile requests.
 */
final class GoDigitalClient
{
    private const PROVIDERS = ['YAS', 'VODACOM', 'HALOTEL', 'AIRTEL'];

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $merchantId;
    private int $timeoutSeconds;

    /** @var null|callable(string, string, array<int, string>, string, int): array{status: int, body: string} */
    private $transport;

    /**
     * @param null|callable(string, string, array<int, string>, string, int): array{status: int, body: string} $transport
     */
    public function __construct(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $merchantId,
        int $timeoutSeconds = 30,
        ?callable $transport = null,
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->merchantId = trim($merchantId);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->transport = $transport;

        foreach (['baseUrl' => $this->baseUrl, 'clientId' => $this->clientId, 'clientSecret' => $this->clientSecret, 'merchantId' => $this->merchantId] as $field => $value) {
            if ($value === '') {
                throw new GoDigitalException("GoDigital {$field} is required.", 422);
            }
        }
    }

    /** Obtain an OAuth token response without saving it anywhere. */
    public function token(): array
    {
        return $this->request(
            'POST',
            '/oauth/token',
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    /** Submit a C2B collection. The provider response is returned unchanged. */
    public function collect(array $parameters): array
    {
        $payment = $this->payment($parameters);
        return $this->authorizedRequest('POST', '/payments/collections/push', $payment, $payment['requestId'], $this->idempotencyKey($parameters, $payment['reference']));
    }

    /** Submit a B2C payout. The provider response is returned unchanged. */
    public function disburse(array $parameters): array
    {
        $payment = $this->payment($parameters);
        return $this->authorizedRequest('POST', '/payments/disbursements', $payment, $payment['requestId'], $this->idempotencyKey($parameters, $payment['reference']));
    }

    /** Get the current provider status for a merchant or provider reference. */
    public function status(string $reference): array
    {
        return $this->authorizedRequest('GET', '/payments/' . rawurlencode($this->reference($reference)));
    }

    /** Read a GoDigital wallet balance. Passing no client ID uses this client's ID. */
    public function walletBalance(?string $clientId = null): array
    {
        $clientId = trim((string) ($clientId ?: $this->clientId));
        if (!preg_match('/^[A-Za-z0-9._-]{2,120}$/', $clientId)) {
            throw new GoDigitalException('Use a valid GoDigital client ID.', 422);
        }

        return $this->authorizedRequest('GET', '/payments/wallets/balance/' . rawurlencode($clientId));
    }

    /** Check a mobile-money recipient before initiating a payment. */
    public function nameCheck(string $providerCode, string $msisdn, ?string $requestId = null): array
    {
        $query = http_build_query([
            'providerCode' => $this->providerCode($providerCode),
            'msisdn' => $this->msisdn($msisdn),
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->request('GET', '/payments/name-check?' . $query, [
            'Accept: application/json',
            'X-Client-Id: ' . $this->clientId,
            'X-Request-Id: ' . $this->requestId($requestId),
        ]);
    }

    /**
     * Validate a GoDigital callback without acknowledging, storing, or logging it.
     * The host application must decide its own idempotency and persistence rules.
     */
    public static function verifyCallback(string $rawBody, array $headers, ?string $callbackSecret = null): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new GoDigitalException('Callback body must be valid JSON.', 400);
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $contentHash = trim((string) ($headers['x-callback-content-sha256'] ?? ''));
        if ($contentHash !== '') {
            $expected = base64_encode(hash('sha256', $rawBody, true));
            if (!hash_equals($expected, $contentHash)) {
                throw new GoDigitalException('Callback content hash did not match.', 400);
            }
        }

        $callbackSecret = trim((string) $callbackSecret);
        if ($callbackSecret !== '') {
            $signature = trim((string) ($headers['x-callback-signature'] ?? ''));
            $expected = base64_encode(hash_hmac('sha256', $rawBody, $callbackSecret, true));
            if ($signature === '' || !hash_equals($expected, $signature)) {
                throw new GoDigitalException('Callback signature did not match.', 400);
            }
        }

        return $payload;
    }

    private function authorizedRequest(string $method, string $endpoint, ?array $payload = null, ?string $requestId = null, ?string $idempotencyKey = null): array
    {
        $token = $this->token()['access_token'] ?? '';
        if (!is_string($token) || $token === '') {
            throw new GoDigitalException('GoDigital OAuth did not return an access token.');
        }

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Client-Id: ' . $this->clientId,
            'X-Request-Id: ' . ($requestId ?: $this->newRequestId()),
            'X-Idempotency-Key: ' . ($idempotencyKey ?: $this->newRequestId()),
            'X-Timestamp: ' . time(),
            'X-Nonce: ' . bin2hex(random_bytes(16)),
            'X-Content-SHA256: ' . base64_encode(hash('sha256', $body, true)),
        ];

        return $this->request($method, $endpoint, $headers, $body);
    }

    private function request(string $method, string $path, array $headers, string $body = ''): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        try {
            $response = $this->transport
                ? ($this->transport)(strtoupper($method), $url, $headers, $body, max(5, $this->timeoutSeconds))
                : $this->curl(strtoupper($method), $url, $headers, $body);
        } catch (GoDigitalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GoDigitalException('GoDigital connection failed: ' . $e->getMessage());
        }

        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new GoDigitalException('GoDigital returned a non-JSON response (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? 'GoDigital rejected the request.');
            $code = isset($decoded['code']) ? ' (' . $decoded['code'] . ')' : '';
            throw new GoDigitalException($message . $code, $status >= 400 && $status < 600 ? $status : 502);
        }

        return $decoded;
    }

    /** @return array{status: int, body: string} */
    private function curl(string $method, string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new GoDigitalException('PHP cURL is required for the GoDigital integration.', 503);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, $this->timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false) {
            throw new GoDigitalException('GoDigital connection failed: ' . ($error ?: 'unknown cURL error.'));
        }

        return ['status' => $status, 'body' => $raw];
    }

    /** @return array<string, string> */
    private function payment(array $input): array
    {
        $amount = trim((string) ($input['amount'] ?? ''));
        if (!preg_match('/^(?:[1-9]\d*)(?:\.\d{1,2})?$/', $amount)) {
            throw new GoDigitalException('amount must be a positive TZS value with at most two decimal places.', 422);
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'TZS')));
        if ($currency !== 'TZS') {
            throw new GoDigitalException('GoDigital payment currency must be TZS.', 422);
        }

        $reference = $this->paymentReference((string) ($input['reference'] ?? ''));
        $narration = trim((string) ($input['narration'] ?? ''));
        if (strlen($narration) > 200) {
            throw new GoDigitalException('narration must be 200 characters or fewer.', 422);
        }
        $callbackUrl = trim((string) ($input['callback_url'] ?? $input['callbackUrl'] ?? ''));
        if (!filter_var($callbackUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($callbackUrl), 'https://')) {
            throw new GoDigitalException('callback_url must be a public HTTPS URL.', 422);
        }

        $payment = [
            'requestId' => $this->requestId($input['request_id'] ?? $input['requestId'] ?? $reference),
            'merchantId' => $this->merchantId,
            'providerCode' => $this->providerCode($input['provider_code'] ?? $input['providerCode'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'msisdn' => $this->msisdn($input['msisdn'] ?? ''),
            'reference' => $reference,
            'callbackUrl' => $callbackUrl,
        ];
        if ($narration !== '') {
            $payment['narration'] = $narration;
        }
        return $payment;
    }

    private function idempotencyKey(array $input, string $fallback): string
    {
        return $this->requestId($input['idempotency_key'] ?? $input['idempotencyKey'] ?? $fallback);
    }

    private function requestId(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        return $value === '' ? $this->newRequestId() : $this->paymentReference($value, 'request_id');
    }

    private function newRequestId(): string
    {
        return 'PMT' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
    }

    private function paymentReference(string $value, string $field = 'reference'): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^PMT[A-Z0-9]{7}$/', $value)) {
            throw new GoDigitalException("{$field} must start with PMT and contain exactly 10 letters or numbers.", 422);
        }
        return $value;
    }

    private function reference(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9._-]{3,120}$/', $value)) {
            throw new GoDigitalException('reference must be 3-120 letters, numbers, periods, underscores, or hyphens.', 422);
        }
        return $value;
    }

    private function providerCode(mixed $value): string
    {
        $provider = strtoupper(trim((string) $value));
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new GoDigitalException('provider_code must be YAS, VODACOM, HALOTEL, or AIRTEL.', 422);
        }
        return $provider;
    }

    private function msisdn(mixed $value): string
    {
        $msisdn = preg_replace('/\s+/', '', (string) $value);
        if (!preg_match('/^255\d{9}$/', $msisdn)) {
            throw new GoDigitalException('msisdn must use Tanzania international format, for example 255754123456.', 422);
        }
        return $msisdn;
    }
}
