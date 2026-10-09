<?php
declare(strict_types=1);

namespace Pesaway;

use App\Config;
use App\PaymentReference;
use App\Store;

/**
 * Server-side PesaWay API client for non-FX, non-crypto products.
 *
 * Credentials and signatures remain on the server. Every outbound request,
 * provider response, and callback is recorded in redacted activity storage.
 */
final class PesawayGateway
{
    private const REGIONS = ['ke', 'ug', 'tz', 'zm', 'gh', 'cm'];
    private const CRYPTO_CURRENCIES = ['BTC', 'ETH', 'USDT', 'USDC', 'SOL', 'TRX', 'XRP', 'BNB'];

    public function __construct(private readonly Store $store)
    {
    }

    /** Return configuration state that is safe to show in the dashboard. */
    public function configuration(): array
    {
        $callbackUrl = $this->callbackUrl(false);
        $baseUrl = trim((string) Config::get('PESAWAY_BASE_URL', ''));

        return [
            'base_url' => $baseUrl,
            'base_url_configured' => $baseUrl !== '',
            'consumer_key_configured' => $this->config('PESAWAY_CONSUMER_KEY') !== '',
            'consumer_secret_configured' => $this->config('PESAWAY_CONSUMER_SECRET') !== '',
            'merchant_code_configured' => $this->config('PESAWAY_MERCHANT_CODE') !== '',
            'region' => $this->config('PESAWAY_REGION') ?: 'tz',
            'callback_url' => $callbackUrl,
            'callback_is_https' => str_starts_with(strtolower($callbackUrl), 'https://'),
            'callback_secret_configured' => $this->config('PESAWAY_CALLBACK_SECRET') !== '',
            'refund_secret_configured' => $this->config('PESAWAY_REFUND_SECRET') !== '',
            'refund_callback_secret_configured' => $this->config('PESAWAY_REFUND_CALLBACK_SECRET') !== '',
        ];
    }

    /** Test OAuth configuration without exposing the resulting bearer token. */
    public function tokenDetails(array $input = []): array
    {
        $region = $this->region($input['region'] ?? '');
        $response = $this->oauthToken($region);

        return [
            'token_type' => (string) ($response['token_type'] ?? 'Bearer'),
            'expires_at' => $response['expires_at'] ?? null,
            'received_at' => gmdate('c'),
        ];
    }

    /** List merchant payment channels, removing any crypto category from the result. */
    public function activeChannels(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $payload = $this->optionalFields($input, ['TransactionType', 'Country', 'Currency']);
        $payload['MerchantCode'] = $this->merchantCode($input['merchant_code'] ?? '');
        if (isset($payload['Currency'])) {
            $payload['Currency'] = $this->currency($payload['Currency']);
        }

        return $this->withoutCrypto($this->authorizedRequest('active_channels', 'POST', '/active-channels/', $payload, $region));
    }

    /** Send a B2C payment to a mobile-money subscriber. */
    public function mobileB2c(array $input): array
    {
        return $this->mobilePayment('mobile_b2c', '/mobile-money/send-payment/', $input, 'PhoneNumber');
    }

    /** Send a B2B payment to a mobile-money account number. */
    public function mobileB2b(array $input): array
    {
        return $this->mobilePayment('mobile_b2b', '/mobile-money/send-payment/', $input, 'AccountNumber');
    }

    /** Request a C2B mobile-money collection. */
    public function mobileC2b(array $input): array
    {
        return $this->mobilePayment('mobile_c2b', '/mobile-money/receive-payment/', $input, 'PhoneNumber');
    }

    /** Submit an OTP supplied by a customer to authorise a mobile transaction. */
    public function authorizeMobile(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $transactionId = $this->providerReference($input['transaction_id'] ?? '', 'Transaction ID');
        $otp = preg_replace('/\s+/', '', (string) ($input['otp'] ?? '')) ?? '';
        if (!preg_match('/^[0-9]{4,12}$/', $otp)) {
            throw new PesawayException('Enter the OTP supplied for this transaction.', 422);
        }

        return $this->authorizedRequest('mobile_authorize', 'GET', '/mobile-money/authorize-transaction/', [
            'TransactionID' => $transactionId,
            'OTP' => $otp,
        ], $region);
    }

    /** Query a previously submitted mobile-money transaction. */
    public function mobileQuery(array $input): array
    {
        return $this->transactionQuery('mobile_query', '/mobile-money/transaction-query/', $input);
    }

    /** Send a payout to a bank account. */
    public function bankPayout(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $payload = [
            'ExternalReference' => $this->reference($input['reference'] ?? ''),
            'Amount' => $this->amount($input['amount'] ?? ''),
            'AccountNumber' => $this->accountNumber($input['account_number'] ?? ''),
            'Channel' => 'Bank',
            'BankName' => $this->text($input['bank_name'] ?? '', 'Bank name', 2, 100),
            'Currency' => $this->currency($input['currency'] ?? ''),
            'MerchantCode' => $this->merchantCode($input['merchant_code'] ?? ''),
            'Reason' => $this->text($input['reason'] ?? '', 'Reason', 2, 250),
            'ResultsUrl' => $this->callbackUrl(true),
        ];

        return $this->authorizedRequest('bank_payout', 'POST', '/bank/send-payment/', $payload, $region);
    }

    /** Query a previously submitted bank transaction. */
    public function bankQuery(array $input): array
    {
        return $this->transactionQuery('bank_query', '/bank/transaction-query/', $input);
    }

    /** Send mobile airtime using the documented PesaWay airtime endpoint. */
    public function sendAirtime(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $payload = [
            'ExternalReference' => $this->reference($input['reference'] ?? ''),
            'Amount' => $this->amount($input['amount'] ?? ''),
            'PhoneNumber' => $this->phoneNumber($input['phone_number'] ?? ''),
            'MerchantCode' => $this->merchantCode($input['merchant_code'] ?? ''),
            'Currency' => $this->currency($input['currency'] ?? ''),
            'Description' => $this->text($input['description'] ?? '', 'Description', 2, 250),
            'ResultsUrl' => $this->callbackUrl(true),
        ];

        return $this->authorizedRequest('airtime', 'POST', '/airtime/send-airtime/', $payload, $region);
    }

    /** Pull a page of historic collections, payments, or refunds. */
    public function pullTransactions(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $start = $this->date($input['start_date'] ?? '', 'Start date');
        $end = $this->date($input['end_date'] ?? '', 'End date');
        if ($end < $start) {
            throw new PesawayException('End date must be on or after start date.', 422);
        }
        $type = ucfirst(strtolower(trim((string) ($input['transaction_type'] ?? ''))));
        if (!in_array($type, ['Collection', 'Payment', 'Refund'], true)) {
            throw new PesawayException('Choose Collection, Payment, or Refund.', 422);
        }
        $offset = filter_var($input['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($offset === false) {
            throw new PesawayException('Offset must be a whole number of zero or more.', 422);
        }
        $payload = [
            'StartDate' => $start,
            'EndDate' => $end,
            'TransType' => $type,
            'OffsetValue' => $offset,
        ];
        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '') {
            $payload['Status'] = $this->text($status, 'Status', 2, 30);
        }

        return $this->authorizedRequest('pull_transactions', 'POST', '/mobile-money/pull-transactions/', $payload, $region);
    }

    /** Initiate a refund using PesaWay's timestamped refund signature. */
    public function refund(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $secret = $this->config('PESAWAY_REFUND_SECRET');
        if ($secret === '') {
            throw new PesawayException('Set PESAWAY_REFUND_SECRET before requesting a refund.', 503);
        }
        $payload = [
            'OriginalReference' => $this->providerReference($input['original_reference'] ?? '', 'Original reference'),
            'ExternalReference' => $this->reference($input['reference'] ?? ''),
            'Amount' => $this->amount($input['amount'] ?? ''),
            'Currency' => $this->currency($input['currency'] ?? ''),
            'Reason' => $this->text($input['reason'] ?? '', 'Reason', 2, 250),
        ];
        $rawBody = $this->encodeJson($payload);
        $timestamp = (string) time();
        $token = $this->oauthToken($region);
        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new PesawayException('PesaWay did not return an access token.', 502);
        }

        return $this->checkedResponse($this->http('refund', 'POST', $this->apiUrl('/refunds/'), [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'X-Region: ' . $region,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $rawBody, $secret),
        ], $payload, $rawBody));
    }

    /** Retrieve the PesaWay merchant balance. */
    public function balance(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $payload = ['MerchantCode' => $this->merchantCode($input['merchant_code'] ?? '')];
        $currency = trim((string) ($input['currency'] ?? ''));
        if ($currency !== '') {
            $payload['Currency'] = $this->currency($currency);
        }
        return $this->authorizedRequest('account_balance', 'POST', '/account-balance/', $payload, $region);
    }

    /** Send one or more non-crypto SMS notifications. */
    public function sendSms(array $input): array
    {
        $region = $this->region($input['region'] ?? '');
        $message = $this->text($input['message'] ?? '', 'Message', 1, 1000);
        $rawDestinations = trim((string) ($input['destination'] ?? ''));
        $destinations = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $rawDestinations) ?: [])));
        if ($destinations === []) {
            throw new PesawayException('Enter at least one SMS destination.', 422);
        }
        $destinations = array_map(fn(string $value): string => $this->phoneNumber($value), $destinations);

        return $this->authorizedRequest('sms_send', 'POST', '/sms/send-bulk-sms/', [
            'message' => $message,
            'destination' => count($destinations) === 1 ? $destinations[0] : $destinations,
        ], $region);
    }

    /** Retrieve the PesaWay SMS credit balance. */
    public function smsBalance(array $input): array
    {
        return $this->authorizedRequest('sms_balance', 'POST', '/sms/account-balance/', [], $this->region($input['region'] ?? ''));
    }

    /** Validate and persist a PesaWay asynchronous callback. */
    public function receiveCallback(string $rawBody, array $headers): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->activity('callback_failed', 'PesaWay callback rejected: invalid JSON.');
            throw new PesawayException('Callback body must be valid JSON.', 400);
        }

        $eventId = trim((string) ($headers['x-event-id'] ?? ''));
        $refundCallbackSecret = $this->config('PESAWAY_REFUND_CALLBACK_SECRET');
        if ($eventId !== '' && $refundCallbackSecret !== '') {
            $timestamp = trim((string) ($headers['x-timestamp'] ?? ''));
            $signature = trim((string) ($headers['x-signature'] ?? ''));
            $expected = hash_hmac('sha256', $timestamp . "\n" . $rawBody, $refundCallbackSecret);
            if ($timestamp === '' || $signature === '' || !hash_equals($expected, $signature)) {
                $this->activity('callback_failed', 'PesaWay refund callback signature did not match.', ['request' => $payload]);
                throw new PesawayException('Callback signature did not match.', 400);
            }
        } elseif (($secret = $this->config('PESAWAY_CALLBACK_SECRET')) !== '') {
            $signature = trim((string) ($headers['signature'] ?? ''));
            $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
            if ($signature === '' || !hash_equals($expected, $signature)) {
                $this->activity('callback_failed', 'PesaWay callback signature did not match.', ['request' => $payload]);
                throw new PesawayException('Callback signature did not match.', 400);
            }
        }

        $callbackId = $eventId ?: (string) ($payload['TransactionID'] ?? $payload['TransactionReference'] ?? hash('sha256', $rawBody));
        $duplicate = $this->store->transaction(function (array &$state) use ($callbackId): bool {
            $callbacks = is_array($state['pesaway_callbacks'] ?? null) ? $state['pesaway_callbacks'] : [];
            foreach ($callbacks as $callback) {
                if (($callback['id'] ?? '') === $callbackId) {
                    return true;
                }
            }
            array_unshift($callbacks, ['id' => $callbackId, 'received_at' => gmdate('c')]);
            $state['pesaway_callbacks'] = array_slice($callbacks, 0, 100);
            return false;
        });
        $this->activity($duplicate ? 'callback_duplicate' : 'callback_received', $duplicate ? 'Duplicate PesaWay callback acknowledged.' : 'PesaWay callback accepted.', [
            'callback_id' => $callbackId,
            'headers' => $headers,
            'request' => $payload,
        ]);

        return ['status' => 'RECEIVED', 'duplicate' => $duplicate];
    }

    /** Return recent redacted outbound and callback activity. */
    public function activityLog(): array
    {
        return $this->store->read('pesaway_activity');
    }

    private function mobilePayment(string $operation, string $endpoint, array $input, string $recipientField): array
    {
        $region = $this->region($input['region'] ?? '');
        $recipient = $recipientField === 'PhoneNumber'
            ? $this->phoneNumber($input['phone_number'] ?? '')
            : $this->accountNumber($input['account_number'] ?? '');
        return $this->authorizedRequest($operation, 'POST', $endpoint, [
            'ExternalReference' => $this->reference($input['reference'] ?? ''),
            'Amount' => $this->amount($input['amount'] ?? ''),
            $recipientField => $recipient,
            'Channel' => $this->channel($input['channel'] ?? ''),
            'Currency' => $this->currency($input['currency'] ?? ''),
            'Reason' => $this->text($input['reason'] ?? '', 'Reason', 2, 250),
            'MerchantCode' => $this->merchantCode($input['merchant_code'] ?? ''),
            'ResultsUrl' => $this->callbackUrl(true),
        ], $region);
    }

    private function transactionQuery(string $operation, string $endpoint, array $input): array
    {
        return $this->authorizedRequest($operation, 'GET', $endpoint, [
            'TransactionReference' => $this->providerReference($input['transaction_reference'] ?? '', 'Transaction reference'),
        ], $this->region($input['region'] ?? ''));
    }

    private function authorizedRequest(string $operation, string $method, string $endpoint, array $payload, string $region): array
    {
        $token = $this->oauthToken($region);
        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new PesawayException('PesaWay did not return an access token.', 502);
        }
        return $this->checkedResponse($this->http($operation, $method, $this->apiUrl($endpoint), [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'X-Region: ' . $region,
        ], $payload));
    }

    private function oauthToken(string $region): array
    {
        $this->requireConfiguration(false);
        $response = $this->http('oauth_token', 'POST', $this->apiUrl('/token/'), [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Region: ' . $region,
        ], [
            'consumer_key' => $this->config('PESAWAY_CONSUMER_KEY'),
            'consumer_secret' => $this->config('PESAWAY_CONSUMER_SECRET'),
            'grant_type' => 'client_credentials',
        ]);
        if (trim((string) ($response['access_token'] ?? '')) === '') {
            throw new PesawayException((string) ($response['message'] ?? $response['description'] ?? 'PesaWay did not issue an access token.'), 502);
        }
        return $response;
    }

    /** Send one PesaWay HTTP request and persist a safe request/response record. */
    private function http(string $operation, string $method, string $url, array $headers, array $payload, ?string $rawBody = null): array
    {
        if (!function_exists('curl_init')) {
            throw new PesawayException('The PHP cURL extension is required for PesaWay requests.', 503);
        }
        $body = $rawBody ?? $this->encodeJson($payload);
        $requestContext = [
            'method' => $method,
            'url' => $url,
            'headers' => $this->redactHeaders($headers),
            'body' => $this->redact($payload),
        ];
        $this->activity($operation . '_request', 'PesaWay request sent: ' . $method . ' ' . parse_url($url, PHP_URL_PATH) . '.', $requestContext);

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()),
            CURLOPT_POSTFIELDS => $body,
        ]);
        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false) {
            $this->activity($operation . '_response', 'PesaWay request failed before a response.', ['http_status' => $status, 'error' => $error]);
            throw new PesawayException('PesaWay connection failed: ' . ($error ?: 'unknown transport error'), 502);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->activity($operation . '_response', 'PesaWay returned a non-JSON response.', ['http_status' => $status, 'body' => substr($raw, 0, 4000)]);
            throw new PesawayException('PesaWay returned an invalid response (HTTP ' . $status . ').', 502);
        }
        $safeResponse = $operation === 'active_channels' ? $this->withoutCrypto($decoded) : $decoded;
        $this->activity($operation . '_response', 'PesaWay response received (HTTP ' . $status . ').', [
            'http_status' => $status,
            'body' => $safeResponse,
        ]);
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['description'] ?? $decoded['message'] ?? $decoded['error'] ?? 'PesaWay rejected the request.');
            throw new PesawayException($message . ' (HTTP ' . $status . ')', $status === 401 || $status === 403 ? 502 : 502);
        }
        return $safeResponse;
    }

    private function checkedResponse(array $response): array
    {
        $code = trim((string) ($response['code'] ?? ''));
        if ($code !== '' && !str_starts_with($code, '200')) {
            throw new PesawayException((string) ($response['description'] ?? $response['message'] ?? 'PesaWay rejected the request.'), 502);
        }
        return $response;
    }

    private function requireConfiguration(bool $merchantRequired = true): void
    {
        foreach (['PESAWAY_BASE_URL', 'PESAWAY_CONSUMER_KEY', 'PESAWAY_CONSUMER_SECRET'] as $key) {
            if ($this->config($key) === '') {
                throw new PesawayException('Set ' . $key . ' in .env before using PesaWay.', 503);
            }
        }
        if ($merchantRequired && $this->config('PESAWAY_MERCHANT_CODE') === '') {
            throw new PesawayException('Set PESAWAY_MERCHANT_CODE in .env before using PesaWay.', 503);
        }
    }

    private function apiUrl(string $path): string
    {
        $base = rtrim($this->config('PESAWAY_BASE_URL'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($base), 'https://')) {
            throw new PesawayException('PESAWAY_BASE_URL must be a valid HTTPS API URL.', 503);
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function callbackUrl(bool $required): string
    {
        $configured = $this->config('PESAWAY_CALLBACK_URL');
        $url = $configured !== '' ? $configured : rtrim($this->config('APP_URL'), '/') . '/webhooks/pesaway';
        if ($required && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://'))) {
            throw new PesawayException('Set PESAWAY_CALLBACK_URL or APP_URL to a public HTTPS callback URL.', 503);
        }
        return $url;
    }

    private function merchantCode(mixed $value): string
    {
        $this->requireConfiguration();
        $code = trim((string) $value) ?: $this->config('PESAWAY_MERCHANT_CODE');
        if (!preg_match('/^[A-Za-z0-9._-]{2,100}$/', $code)) {
            throw new PesawayException('Use a valid PesaWay merchant code.', 422);
        }
        return $code;
    }

    private function region(mixed $value): string
    {
        $region = strtolower(trim((string) $value) ?: $this->config('PESAWAY_REGION') ?: 'tz');
        if (!in_array($region, self::REGIONS, true)) {
            throw new PesawayException('Choose a supported PesaWay region: ' . implode(', ', self::REGIONS) . '.', 422);
        }
        return $region;
    }

    private function reference(mixed $value): string
    {
        $reference = strtoupper(trim((string) $value));
        if (!PaymentReference::isValid($reference)) {
            throw new PesawayException('Reference must be a 10-character PMT ID.', 422);
        }
        return $reference;
    }

    private function providerReference(mixed $value, string $label): string
    {
        $reference = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9._:-]{2,120}$/', $reference)) {
            throw new PesawayException($label . ' is not valid.', 422);
        }
        return $reference;
    }

    private function amount(mixed $value): string
    {
        $amount = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            throw new PesawayException('Amount must be greater than zero.', 422);
        }
        return $amount;
    }

    private function phoneNumber(mixed $value): string
    {
        $phone = preg_replace('/[\s+()-]/', '', trim((string) $value)) ?? '';
        if (!preg_match('/^[0-9]{7,18}$/', $phone)) {
            throw new PesawayException('Use a valid phone number with country code.', 422);
        }
        return $phone;
    }

    private function accountNumber(mixed $value): string
    {
        $account = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9._ -]{2,80}$/', $account)) {
            throw new PesawayException('Use a valid account number.', 422);
        }
        return $account;
    }

    private function channel(mixed $value): string
    {
        return $this->text($value, 'Channel', 2, 70);
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z]{3}$/', $currency) || in_array($currency, self::CRYPTO_CURRENCIES, true)) {
            throw new PesawayException('Use a supported non-crypto three-letter currency.', 422);
        }
        return $currency;
    }

    private function text(mixed $value, string $label, int $min, int $max): string
    {
        $text = trim((string) $value);
        if (strlen($text) < $min || strlen($text) > $max) {
            throw new PesawayException($label . ' must contain between ' . $min . ' and ' . $max . ' characters.', 422);
        }
        return $text;
    }

    private function date(mixed $value, string $label): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new PesawayException($label . ' must use YYYY-MM-DD.', 422);
        }
        return $date->format('Y-m-d');
    }

    private function optionalFields(array $input, array $names): array
    {
        $output = [];
        foreach ($names as $name) {
            $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $output[$name] = $name === 'TransactionType'
                    ? $this->text($value, 'Transaction type', 2, 30)
                    : $this->text($value, $name, 2, 30);
            }
        }
        return $output;
    }

    private function timeout(): int
    {
        $timeout = filter_var($this->config('PESAWAY_TIMEOUT_SECONDS') ?: '30', FILTER_VALIDATE_INT);
        return $timeout === false ? 30 : max(5, min($timeout, 120));
    }

    private function config(string $key): string
    {
        return trim((string) Config::get($key, ''));
    }

    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PesawayException('Unable to encode the PesaWay request.', 422);
        }
    }

    private function activity(string $type, string $message, array $context = []): void
    {
        $this->store->transaction(function (array &$state) use ($type, $message, $context): void {
            $entries = is_array($state['pesaway_activity'] ?? null) ? $state['pesaway_activity'] : [];
            array_unshift($entries, [
                'type' => $type,
                'message' => $message,
                'at' => gmdate('c'),
                'context' => $this->redact($context),
            ]);
            $state['pesaway_activity'] = array_slice($entries, 0, 150);
        });
    }

    private function redactHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $safe[$name] = preg_match('/authorization|secret|signature/i', $name) ? '[redacted]' : trim($value);
        }
        return $safe;
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $name => $item) {
                $childKey = is_int($name) || ctype_digit((string) $name) ? $key : (string) $name;
                $safe[$name] = $this->redact($item, $childKey);
            }
            return $safe;
        }
        if ($key !== null && preg_match('/secret|token|authorization|signature|password|otp|consumer_key|pin/i', $key)) {
            return '[redacted]';
        }
        if ($key !== null && preg_match('/phone|account|destination|msisdn|mobile/i', $key) && is_scalar($value)) {
            $digits = preg_replace('/\D/', '', (string) $value) ?? '';
            return strlen($digits) > 4 ? str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4) : '[redacted]';
        }
        return $value;
    }

    private function withoutCrypto(array $response): array
    {
        foreach ($response as $key => $value) {
            if (str_contains(strtolower((string) $key), 'crypto')) {
                unset($response[$key]);
                continue;
            }
            if (is_array($value)) {
                $response[$key] = $this->withoutCrypto($value);
            } elseif (is_string($value) && str_contains(strtolower($value), 'crypto')) {
                unset($response[$key]);
            }
        }
        return $response;
    }
}
