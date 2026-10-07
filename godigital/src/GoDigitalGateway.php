<?php
declare(strict_types=1);

namespace GoDigital;

use App\Config;
use App\Store;

/**
 * Server-side client for GoDigital Payments API v1.
 *
 * The OAuth access token is intentionally held only in memory. Payment and
 * callback activity is retained in the existing application state store so a
 * restart cannot cause a second customer collection to be submitted blindly.
 */
final class GoDigitalGateway
{
    private const PROVIDERS = ['YAS', 'VODACOM', 'HALOTEL', 'AIRTEL'];

    public function __construct(private readonly Store $store)
    {
    }

    /** Configuration data that is safe to display in the dashboard. */
    public function configuration(): array
    {
        $callbackUrl = $this->callbackUrl(false);
        $baseUrl = trim((string) Config::get('GODIGITAL_BASE_URL', ''));

        return [
            'base_url' => $baseUrl,
            'base_url_configured' => $baseUrl !== '',
            'client_id_configured' => trim((string) Config::get('GODIGITAL_CLIENT_ID', '')) !== '',
            'client_secret_configured' => trim((string) Config::get('GODIGITAL_CLIENT_SECRET', '')) !== '',
            'merchant_id_configured' => trim((string) Config::get('GODIGITAL_MERCHANT_ID', '')) !== '',
            'callback_url' => $callbackUrl,
            'callback_is_https' => str_starts_with(strtolower($callbackUrl), 'https://'),
            'signature_note' => 'Callback signature validation is off until GoDigital confirms the exact signed value for this merchant.',
        ];
    }

    /** Requests an OAuth token but deliberately never returns the token itself. */
    public function tokenDetails(): array
    {
        $this->requireConfiguration(false);
        $response = $this->oauthToken();
        $this->activity('oauth_token', 'OAuth token accepted by GoDigital.');

        return [
            'token_type' => (string) ($response['token_type'] ?? 'Bearer'),
            'expires_in' => (int) ($response['expires_in'] ?? 0),
            'scope' => $response['scope'] ?? null,
            'received_at' => gmdate('c'),
        ];
    }

    /** Submit a C2B mobile-money collection request. */
    public function collection(array $input, string $idempotencyKey = ''): array
    {
        return $this->submit('C2B_COLLECTION', '/payments/collections/push', $input, $idempotencyKey);
    }

    /** Submit a B2C mobile-money payout request. */
    public function disbursement(array $input, string $idempotencyKey = ''): array
    {
        return $this->submit('B2C_DISBURSEMENT', '/payments/disbursements', $input, $idempotencyKey);
    }

    /** Retrieve a GoDigital transaction by its merchant reference. */
    public function status(string $reference): array
    {
        $this->requireConfiguration(false);
        $reference = $this->reference($reference);
        $response = $this->authorizedRequest('GET', '/payments/' . rawurlencode($reference));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->updateFromProvider($reference, $data, $response);
        $this->activity('status', 'Status checked for ' . $reference . '.', ['reference' => $reference]);

        return [
            'reference' => $reference,
            'provider_status' => $data['transactionStatus'] ?? $response['status'] ?? null,
            'transaction_id' => $data['transactionId'] ?? null,
            'response' => $response,
        ];
    }

    /** Retrieve a wallet balance for the configured or supplied GoDigital client ID. */
    public function balance(string $clientId = ''): array
    {
        $this->requireConfiguration(false);
        $clientId = trim($clientId) ?: $this->config('GODIGITAL_CLIENT_ID');
        if (!preg_match('/^[A-Za-z0-9._-]{2,120}$/', $clientId)) {
            throw new GoDigitalException('Use a valid GoDigital client ID.', 422);
        }

        $response = $this->authorizedRequest('GET', '/payments/wallets/balance/' . rawurlencode($clientId));
        $this->activity('balance', 'Wallet balance checked.');
        return $response;
    }

    /**
     * Accept a GoDigital callback. Duplicate callback IDs and transaction IDs
     * are recorded once, but both receive the required 200 acknowledgement.
     */
    public function receiveCallback(string $rawBody, array $headers): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new GoDigitalException('Callback body must be valid JSON.', 400);
        }

        $contentHash = trim((string) ($headers['x-callback-content-sha256'] ?? ''));
        if ($contentHash !== '') {
            $expected = base64_encode(hash('sha256', $rawBody, true));
            if (!hash_equals($expected, $contentHash)) {
                throw new GoDigitalException('Callback content hash did not match.', 400);
            }
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = trim((string) ($data['reference'] ?? ''));
        $transactionId = trim((string) ($data['transactionId'] ?? ''));
        $callbackId = trim((string) ($headers['x-callback-id'] ?? ''));
        if ($callbackId === '' && $transactionId === '') {
            throw new GoDigitalException('Callback is missing both X-Callback-Id and data.transactionId.', 400);
        }

        $duplicate = $this->store->transaction(function (array &$state) use ($callbackId, $reference, $transactionId, $data, $payload): bool {
            $callbackKey = $callbackId === '' ? null : 'callback:' . $callbackId;
            $transactionKey = $transactionId === '' ? null : 'transaction:' . $transactionId;
            if (($callbackKey !== null && isset($state['godigital_callbacks'][$callbackKey]))
                || ($transactionKey !== null && isset($state['godigital_callbacks'][$transactionKey]))) {
                return true;
            }

            $record = [
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'received_at' => gmdate('c'),
            ];
            if ($callbackKey !== null) {
                $state['godigital_callbacks'][$callbackKey] = $record;
            }
            if ($transactionKey !== null) {
                $state['godigital_callbacks'][$transactionKey] = $record;
            }
            if ($reference !== '' && isset($state['godigital_transactions'][$reference])) {
                $record = $state['godigital_transactions'][$reference];
                $record['provider_status'] = $data['transactionStatus'] ?? $record['provider_status'] ?? null;
                $record['transaction_id'] = $transactionId ?: ($record['transaction_id'] ?? null);
                $record['provider_response'] = $payload;
                $record['updated_at'] = gmdate('c');
                $state['godigital_transactions'][$reference] = $record;
            }
            return false;
        });

        $this->activity('callback', $duplicate ? 'Duplicate callback acknowledged.' : 'Callback accepted.', [
            'reference' => $reference,
            'transaction_id' => $transactionId,
        ]);

        return ['status' => 'RECEIVED', 'message' => 'Callback accepted', 'duplicate' => $duplicate];
    }

    /** Recent redacted integration activity for the dashboard. */
    public function activityLog(): array
    {
        return $this->store->transaction(function (array $state): array {
            return array_slice(array_reverse($state['godigital_activity'] ?? []), 0, 30);
        }, false);
    }

    private function submit(string $operation, string $endpoint, array $input, string $idempotencyKey): array
    {
        $this->requireConfiguration(true);
        $payment = $this->paymentInput($input);
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);
        $fingerprint = hash('sha256', json_encode([$operation, $payment], JSON_UNESCAPED_SLASHES));
        $reservation = $this->reserve($operation, $payment, $idempotencyKey, $fingerprint);
        if ($reservation['replayed']) {
            return $this->resource($reservation['record']) + ['replayed' => true];
        }

        try {
            $response = $this->authorizedRequest('POST', $endpoint, $payment, $payment['requestId'], $idempotencyKey);
            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $record = $this->store->transaction(function (array &$state) use ($payment, $data, $response): array {
                $record = $state['godigital_transactions'][$payment['reference']];
                $record['provider_status'] = $data['transactionStatus'] ?? $response['status'] ?? 'ACCEPTED';
                $record['transaction_id'] = $data['transactionId'] ?? null;
                $record['provider_response'] = $response;
                $record['updated_at'] = gmdate('c');
                return $state['godigital_transactions'][$payment['reference']] = $record;
            });
            $this->activity(strtolower($operation), "{$operation} submitted for {$payment['reference']}.", [
                'reference' => $payment['reference'],
                'msisdn' => $payment['msisdn'],
            ]);
            return $this->resource($record) + ['replayed' => false];
        } catch (\Throwable $e) {
            $this->store->transaction(function (array &$state) use ($payment): void {
                if (!isset($state['godigital_transactions'][$payment['reference']])) {
                    return;
                }
                $state['godigital_transactions'][$payment['reference']]['provider_status'] = 'UNKNOWN';
                $state['godigital_transactions'][$payment['reference']]['updated_at'] = gmdate('c');
            });
            $this->activity(strtolower($operation), "{$operation} could not be confirmed; check status before retrying.", [
                'reference' => $payment['reference'],
                'msisdn' => $payment['msisdn'],
            ]);
            throw $e;
        }
    }

    private function reserve(string $operation, array $payment, string $idempotencyKey, string $fingerprint): array
    {
        return $this->store->transaction(function (array &$state) use ($operation, $payment, $idempotencyKey, $fingerprint): array {
            $existingReference = $state['godigital_idempotency'][$idempotencyKey] ?? null;
            if ($existingReference !== null) {
                $record = $state['godigital_transactions'][$existingReference] ?? null;
                if (!$record || !hash_equals((string) ($record['fingerprint'] ?? ''), $fingerprint)) {
                    throw new GoDigitalException('This idempotency key was already used with different payment data.', 409);
                }
                return ['record' => $record, 'replayed' => true];
            }
            if (isset($state['godigital_transactions'][$payment['reference']])) {
                throw new GoDigitalException('This payment reference was already used. Use a new reference or the original idempotency key.', 409);
            }

            $record = [
                'reference' => $payment['reference'],
                'operation' => $operation,
                'request_id' => $payment['requestId'],
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'amount' => $payment['amount'],
                'currency' => $payment['currency'],
                'provider_code' => $payment['providerCode'],
                'msisdn' => $payment['msisdn'],
                'narration' => $payment['narration'] ?? null,
                'provider_status' => 'SUBMITTED',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ];
            $state['godigital_transactions'][$payment['reference']] = $record;
            $state['godigital_idempotency'][$idempotencyKey] = $payment['reference'];
            return ['record' => $record, 'replayed' => false];
        });
    }

    private function authorizedRequest(string $method, string $endpoint, ?array $payload = null, ?string $requestId = null, ?string $idempotencyKey = null): array
    {
        $token = $this->oauthToken()['access_token'] ?? '';
        if (!is_string($token) || $token === '') {
            throw new GoDigitalException('GoDigital OAuth did not return an access token.');
        }

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'X-Client-Id: ' . $this->config('GODIGITAL_CLIENT_ID'),
            'X-Request-Id: ' . ($requestId ?: $this->uuid()),
            'X-Idempotency-Key: ' . ($idempotencyKey ?: $this->uuid()),
            'X-Timestamp: ' . time(),
            'X-Nonce: ' . bin2hex(random_bytes(16)),
            'X-Content-SHA256: ' . base64_encode(hash('sha256', $body, true)),
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        return $this->http($method, $this->apiUrl($endpoint), $headers, $body);
    }

    private function oauthToken(): array
    {
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->config('GODIGITAL_CLIENT_ID'),
            'client_secret' => $this->config('GODIGITAL_CLIENT_SECRET'),
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->http('POST', $this->apiUrl('/oauth/token'), [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], $body);
    }

    private function http(string $method, string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new GoDigitalException('PHP cURL is required for the GoDigital integration.', 503);
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, (int) Config::get('GODIGITAL_TIMEOUT_SECONDS', '30')),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($raw === false) {
            throw new GoDigitalException('GoDigital connection failed: ' . ($curlError ?: 'unknown cURL error.'));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new GoDigitalException('GoDigital returned a non-JSON response (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? 'GoDigital rejected the request.');
            $code = isset($decoded['code']) ? ' (' . $decoded['code'] . ')' : '';
            throw new GoDigitalException($message . $code);
        }
        return $decoded;
    }

    private function paymentInput(array $input): array
    {
        $amount = trim((string) ($input['amount'] ?? ''));
        if (!preg_match('/^(?:[1-9]\d*)(?:\.\d{1,2})?$/', $amount)) {
            throw new GoDigitalException('amount must be a positive TZS value with at most two decimal places.', 422);
        }
        $provider = strtoupper(trim((string) ($input['provider_code'] ?? $input['providerCode'] ?? '')));
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new GoDigitalException('provider_code must be YAS, VODACOM, HALOTEL, or AIRTEL.', 422);
        }
        $msisdn = preg_replace('/\s+/', '', (string) ($input['msisdn'] ?? ''));
        if (!preg_match('/^255\d{9}$/', $msisdn)) {
            throw new GoDigitalException('msisdn must use Tanzania international format, for example 255754123456.', 422);
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'TZS')));
        if ($currency !== 'TZS') {
            throw new GoDigitalException('GoDigital payment currency must be TZS.', 422);
        }
        $reference = $this->reference((string) ($input['reference'] ?? ''));
        $narration = trim((string) ($input['narration'] ?? ''));
        if (strlen($narration) > 200) {
            throw new GoDigitalException('narration must be 200 characters or fewer.', 422);
        }

        return array_filter([
            'requestId' => $this->requestId($input['request_id'] ?? $input['requestId'] ?? ''),
            'merchantId' => $this->config('GODIGITAL_MERCHANT_ID'),
            'providerCode' => $provider,
            'amount' => $amount,
            'currency' => $currency,
            'msisdn' => $msisdn,
            'reference' => $reference,
            'callbackUrl' => $this->callbackUrl(true),
            'narration' => $narration === '' ? null : $narration,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function requestId(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $this->uuid();
        }
        if (!preg_match('/^[A-Za-z0-9_-]{8,120}$/', $value)) {
            throw new GoDigitalException('request_id must be 8-120 letters, numbers, underscores, or hyphens.', 422);
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

    private function idempotencyKey(string $value): string
    {
        $value = trim($value) ?: $this->uuid();
        if (!preg_match('/^[A-Za-z0-9._-]{8,120}$/', $value)) {
            throw new GoDigitalException('Idempotency-Key must be 8-120 letters, numbers, periods, underscores, or hyphens.', 422);
        }
        return $value;
    }

    private function callbackUrl(bool $required): string
    {
        $url = trim((string) Config::get('GODIGITAL_CALLBACK_URL', ''));
        if ($url === '') {
            $base = rtrim((string) Config::get('APP_URL', ''), '/');
            $url = $base === '' ? '' : $base . '/webhooks/godigital';
        }
        if ($required && !filter_var($url, FILTER_VALIDATE_URL) || ($required && !str_starts_with(strtolower($url), 'https://'))) {
            throw new GoDigitalException('Set GODIGITAL_CALLBACK_URL to a public HTTPS URL before submitting payments.', 503);
        }
        return $url;
    }

    private function requireConfiguration(bool $payment): void
    {
        $keys = ['GODIGITAL_BASE_URL', 'GODIGITAL_CLIENT_ID', 'GODIGITAL_CLIENT_SECRET'];
        if ($payment) {
            $keys[] = 'GODIGITAL_MERCHANT_ID';
        }
        $missing = array_filter($keys, fn(string $key): bool => trim((string) Config::get($key, '')) === '');
        if ($missing) {
            throw new GoDigitalException('Missing GoDigital configuration: ' . implode(', ', $missing) . '.', 503);
        }
    }

    private function config(string $key): string
    {
        return trim((string) Config::get($key, ''));
    }

    private function apiUrl(string $endpoint): string
    {
        $base = rtrim($this->config('GODIGITAL_BASE_URL'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($base), 'https://')) {
            throw new GoDigitalException('GODIGITAL_BASE_URL must be an HTTPS URL ending in /api/v1.', 503);
        }
        return $base . '/' . ltrim($endpoint, '/');
    }

    private function updateFromProvider(string $reference, array $data, array $response): void
    {
        $this->store->transaction(function (array &$state) use ($reference, $data, $response): void {
            if (!isset($state['godigital_transactions'][$reference])) {
                return;
            }
            $record = $state['godigital_transactions'][$reference];
            $record['provider_status'] = $data['transactionStatus'] ?? $record['provider_status'] ?? null;
            $record['transaction_id'] = $data['transactionId'] ?? $record['transaction_id'] ?? null;
            $record['provider_response'] = $response;
            $record['updated_at'] = gmdate('c');
            $state['godigital_transactions'][$reference] = $record;
        });
    }

    private function resource(array $record): array
    {
        return [
            'reference' => $record['reference'],
            'operation' => $record['operation'],
            'request_id' => $record['request_id'],
            'amount' => $record['amount'],
            'currency' => $record['currency'],
            'provider_code' => $record['provider_code'],
            'provider_status' => $record['provider_status'],
            'transaction_id' => $record['transaction_id'] ?? null,
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
            'provider_response' => $record['provider_response'] ?? null,
        ];
    }

    private function activity(string $type, string $message, array $context = []): void
    {
        $context = array_filter($context, static function (mixed $value, string $key): bool {
            return $key !== 'msisdn' || $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
        if (isset($context['msisdn'])) {
            $context['msisdn'] = '***' . substr((string) $context['msisdn'], -4);
        }
        $this->store->transaction(function (array &$state) use ($type, $message, $context): void {
            $state['godigital_activity'] ??= [];
            $state['godigital_activity'][] = [
                'at' => gmdate('c'),
                'type' => $type,
                'message' => $message,
                'context' => $context,
            ];
            if (count($state['godigital_activity']) > 100) {
                $state['godigital_activity'] = array_slice($state['godigital_activity'], -100);
            }
        });
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-' . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }
}
