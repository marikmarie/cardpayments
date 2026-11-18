<?php
declare(strict_types=1);

namespace GoDigital;

use App\Config;
use GoDigital\Sdk\GoDigitalClient;

/**
 * Thin dashboard adapter around the stateless SDK.
 *
 * It only reads this application's environment so the browser test screen can
 * call the provider. No payment, callback, wallet, or activity state is kept.
 */
final class GoDigitalGateway
{
    /** Configuration data that is safe to display in the dashboard. */
    public function configuration(): array
    {
        $callbackUrl = $this->callbackUrl(false);
        $baseUrl = trim((string) Config::get('GODIGITAL_BASE_URL', ''));

        return [
            'base_url' => $baseUrl,
            'base_url_configured' => $baseUrl !== '',
            'client_id_configured' => $this->config('GODIGITAL_CLIENT_ID') !== '',
            'client_secret_configured' => $this->config('GODIGITAL_CLIENT_SECRET') !== '',
            'merchant_id_configured' => $this->config('GODIGITAL_MERCHANT_ID') !== '',
            'callback_url' => $callbackUrl,
            'callback_is_https' => str_starts_with(strtolower($callbackUrl), 'https://'),
            'callback_secret_configured' => $this->config('GODIGITAL_CALLBACK_SECRET') !== '',
            'signature_note' => $this->config('GODIGITAL_CALLBACK_SECRET') !== ''
                ? 'Callback signature validation is active.'
                : 'Add GODIGITAL_CALLBACK_SECRET only if GoDigital enables callback signatures for this merchant.',
        ];
    }

    /** Request OAuth details without exposing or retaining the access token. */
    public function tokenDetails(): array
    {
        $response = $this->client()->token();
        return [
            'token_type' => (string) ($response['token_type'] ?? 'Bearer'),
            'expires_in' => (int) ($response['expires_in'] ?? 0),
            'scope' => $response['scope'] ?? null,
        ];
    }

    /** Send a collection request and return the provider response for this browser session only. */
    public function collection(array $input, string $idempotencyKey = ''): array
    {
        if ($idempotencyKey !== '') {
            $input['idempotency_key'] = $idempotencyKey;
        }
        $input['callback_url'] ??= $this->callbackUrl(true);
        return $this->client()->collect($input);
    }

    /** Send a payout request and return the provider response for this browser session only. */
    public function disbursement(array $input, string $idempotencyKey = ''): array
    {
        if ($idempotencyKey !== '') {
            $input['idempotency_key'] = $idempotencyKey;
        }
        $input['callback_url'] ??= $this->callbackUrl(true);
        return $this->client()->disburse($input);
    }

    public function status(string $reference): array
    {
        return $this->client()->status($reference);
    }

    public function balance(string $clientId = ''): array
    {
        return $this->client()->walletBalance($clientId ?: null);
    }

    public function nameCheck(array $input): array
    {
        return $this->client()->nameCheck(
            (string) ($input['provider_code'] ?? $input['providerCode'] ?? ''),
            (string) ($input['msisdn'] ?? ''),
            isset($input['request_id']) ? (string) $input['request_id'] : null,
        );
    }

    /** Validate a callback only. Its persistence and duplicate handling belong to the host application. */
    public function receiveCallback(string $rawBody, array $headers): array
    {
        GoDigitalClient::verifyCallback($rawBody, $headers, $this->config('GODIGITAL_CALLBACK_SECRET'));
        return ['status' => 'RECEIVED', 'message' => 'Callback verified'];
    }

    private function client(): GoDigitalClient
    {
        return new GoDigitalClient(
            baseUrl: $this->requiredConfig('GODIGITAL_BASE_URL'),
            clientId: $this->requiredConfig('GODIGITAL_CLIENT_ID'),
            clientSecret: $this->requiredConfig('GODIGITAL_CLIENT_SECRET'),
            merchantId: $this->requiredConfig('GODIGITAL_MERCHANT_ID'),
            timeoutSeconds: max(5, (int) Config::get('GODIGITAL_TIMEOUT_SECONDS', '30')),
        );
    }

    private function callbackUrl(bool $required): string
    {
        $url = trim((string) Config::get('GODIGITAL_CALLBACK_URL', ''));
        if ($url === '') {
            $baseUrl = rtrim((string) Config::get('APP_URL', ''), '/');
            $url = $baseUrl === '' ? '' : $baseUrl . '/webhooks/godigital';
        }
        if ($required && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://'))) {
            throw new GoDigitalException('Set GODIGITAL_CALLBACK_URL to a public HTTPS URL before submitting payments.', 503);
        }
        return $url;
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->config($key);
        if ($value === '') {
            throw new GoDigitalException("Missing {$key} in .env.", 503);
        }
        return $value;
    }

    private function config(string $key): string
    {
        return trim((string) Config::get($key, ''));
    }
}
