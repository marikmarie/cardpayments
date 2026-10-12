<?php
declare(strict_types=1);

namespace MomoPay;

use App\Config;
use App\PaymentReference;
use App\Store;

/**
 * Small server-side client for MTN MoMo Pay Collection and Disbursement.
 *
 * MTN API users and API keys are provisioned once in the MTN portal. This
 * gateway reads them from .env and never creates or exposes credentials while
 * taking a payment. The MTN-required UUID is kept as a provider reference;
 * the merchant-facing ID remains the standard PMT reference.
 */
final class MomoPayGateway
{
    private const PRODUCTS = ['collection', 'disbursement'];

    public function __construct(private readonly Store $store)
    {
    }

    /** Safe configuration state for the dashboard. */
    public function configuration(): array
    {
        $callbackUrl = $this->callbackUrl(false);

        return [
            'base_url' => $this->config('MOMOPAY_BASE_URL'),
            'base_url_configured' => $this->config('MOMOPAY_BASE_URL') !== '',
            'target_environment' => $this->targetEnvironment(),
            'currency' => $this->defaultCurrency(),
            'collection_ready' => $this->productConfigured('collection'),
            'disbursement_ready' => $this->productConfigured('disbursement'),
            'callback_url' => $callbackUrl,
            'callback_configured' => filter_var($callbackUrl, FILTER_VALIDATE_URL) !== false,
        ];
    }

    /** Verify a configured product token without returning the access token. */
    public function tokenDetails(string $product): array
    {
        $product = $this->product($product);
        $token = $this->token($product);
        return [
            'product' => $product,
            'provider_response' => $this->redact($this->providerBody($token)),
        ];
    }

    /** Request a customer-authorised collection. */
    public function collection(array $input): array
    {
        return $this->submit('collection', 'collection_request', '/collection/v1_0/requesttopay', $input, 'payer');
    }

    /** Transfer funds from the disbursement account to a recipient. */
    public function disbursement(array $input): array
    {
        return $this->submit('disbursement', 'disbursement_transfer', '/disbursement/v1_0/transfer', $input, 'payee');
    }

    /** Submit the enabled v2 collection withdrawal request from the supplied legacy collection. */
    public function withdrawal(array $input): array
    {
        return $this->submit('collection', 'collection_withdrawal', '/collection/v2_0/requesttowithdraw', $input, 'payer');
    }

    /** Look up the current provider state using a PMT ID or an MTN UUID. */
    public function status(array $input): array
    {
        $reference = trim((string) ($input['reference'] ?? ''));
        if ($reference === '') {
            throw new MomoPayException('Enter a PMT payment ID or MTN reference ID.', 422);
        }
        $transaction = $this->transaction($reference);
        if (($transaction['operation'] ?? '') === 'collection_withdrawal') {
            throw new MomoPayException('Use the callback or Logs tab to confirm this Collection v2 withdrawal.', 422);
        }
        $providerReference = $transaction['provider_reference'] ?? $reference;
        $product = $transaction['product'] ?? $this->product($input['product'] ?? '');
        if (!$this->isUuid($providerReference)) {
            throw new MomoPayException('Use the MTN UUID returned when the request was accepted.', 422);
        }
        $endpoint = $product === 'collection'
            ? '/collection/v1_0/requesttopay/' . rawurlencode($providerReference)
            : '/disbursement/v1_0/transfer/' . rawurlencode($providerReference);
        $response = $this->authorized($product, 'status_' . $product, 'GET', $endpoint);
        $this->updateTransaction($transaction['reference'] ?? $reference, $response, $this->providerStatus($response));

        return [
            'reference' => $transaction['reference'] ?? $reference,
            'provider_reference' => $providerReference,
            'product' => $product,
            'provider_response' => $this->providerBody($response),
        ];
    }

    /** Retrieve the balance for Collection or Disbursement. */
    public function balance(array $input): array
    {
        $product = $this->product($input['product'] ?? '');
        $endpoint = '/' . $product . '/v1_0/account/balance';
        return $this->authorized($product, $product . '_balance', 'GET', $endpoint);
    }

    /** Verify whether a customer MSISDN is active, or retrieve enabled basic information. */
    public function accountHolder(array $input): array
    {
        $product = $this->product($input['product'] ?? 'collection');
        $query = strtolower(trim((string) ($input['query'] ?? 'active')));
        if (!in_array($query, ['active', 'basicuserinfo'], true)) {
            throw new MomoPayException('Choose active or basic user information.', 422);
        }
        $phone = $this->phoneNumber($input['phone_number'] ?? '');
        return $this->authorized($product, 'account_holder_' . $product . '_' . $query, 'GET', '/' . $product . '/v1_0/accountholder/msisdn/' . rawurlencode($phone) . '/' . $query);
    }

    /** Receive and record a callback. MTN can call this endpoint by POST or PUT. */
    public function receiveCallback(string $rawBody, array $headers): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->activity('callback_failed', 'MoMo Pay callback rejected: invalid JSON.', [
                'source' => 'MTN MoMo callback',
                'raw_body' => $this->redactProviderBody($rawBody),
            ]);
            throw new MomoPayException('Callback body must be valid JSON.', 400);
        }
        $providerReference = trim((string) ($payload['referenceId'] ?? $payload['X-Reference-Id'] ?? $payload['externalId'] ?? ''));
        $callbackId = $providerReference !== '' ? $providerReference : hash('sha256', $rawBody);
        $duplicate = $this->store->transaction(function (array &$state) use ($callbackId): bool {
            $callbacks = is_array($state['momopay_callbacks'] ?? null) ? $state['momopay_callbacks'] : [];
            foreach ($callbacks as $callback) {
                if (($callback['id'] ?? '') === $callbackId) {
                    return true;
                }
            }
            array_unshift($callbacks, ['id' => $callbackId, 'received_at' => gmdate('c')]);
            $state['momopay_callbacks'] = array_slice($callbacks, 0, 100);
            return false;
        });

        $status = strtoupper((string) ($payload['status'] ?? ''));
        if ($providerReference !== '' && $status !== '') {
            if (PaymentReference::isValid(strtoupper($providerReference))) {
                $this->updateTransaction(strtoupper($providerReference), $payload, $status);
            } else {
                $this->updateTransactionByProviderReference($providerReference, $payload, $status);
            }
        }
        $this->activity($duplicate ? 'callback_duplicate' : 'callback_received', $duplicate ? 'Duplicate MoMo Pay callback recorded.' : 'MoMo Pay callback recorded.', [
            'source' => 'MTN MoMo callback',
            'callback_id' => $callbackId,
            'headers' => $headers,
            'raw_body' => $this->redactProviderBody($rawBody),
        ]);

        return ['status' => 'RECEIVED', 'duplicate' => $duplicate];
    }

    /** Recent redacted MoMo Pay requests, responses, and callbacks. */
    public function activityLog(): array
    {
        return $this->store->read('momopay_activity');
    }

    /** Run one controlled sandbox SIT case and retain its request/response log. */
    public function sit(array $input): array
    {
        if ($this->targetEnvironment() !== 'sandbox') {
            throw new MomoPayException('SIT cases can only run while MOMOPAY_TARGET_ENVIRONMENT is sandbox.', 403);
        }

        $product = $this->product($input['product'] ?? '');
        $case = $this->sitCase($input['case'] ?? '');
        return match (true) {
            str_starts_with($case, 'auth_') => $this->sitToken($product, $case),
            str_starts_with($case, 'payment_') => $this->sitPayment($product, $case, $input),
            str_starts_with($case, 'status_') => $this->sitStatus($product, $case, $input),
            str_starts_with($case, 'account_') => $this->sitAccountHolder($product, $case, $input),
            str_starts_with($case, 'balance_') => $this->sitBalance($product, $case),
            default => throw new MomoPayException('Choose a supported MoMo SIT case.', 422),
        };
    }

    /** Exercise the OAuth cases from the MTN SIT sheet without exposing a token. */
    private function sitToken(string $product, string $case): array
    {
        $overrides = match ($case) {
            'auth_invalid_subscription' => ['subscription_key' => 'invalid-sit-subscription-key'],
            'auth_invalid_api_key' => ['api_key' => 'invalid-sit-api-key'],
            default => [],
        };
        $response = $this->token($product, 'sit_' . $product . '_' . $case, $overrides);

        return [
            'case' => $case,
            'product' => $product,
            'provider_response' => $this->redact($this->providerBody($response)),
        ];
    }

    /** Submit a constrained Collection or Disbursement test case to the sandbox. */
    private function sitPayment(string $product, string $case, array $input): array
    {
        $referenceInput = trim((string) ($input['reference'] ?? ''));
        $reference = $this->reference($referenceInput !== '' ? $referenceInput : PaymentReference::generate());
        $providerReference = $this->sitProviderReference($input, $case === 'payment_duplicate_reference');
        $partyField = $product === 'collection' ? 'payer' : 'payee';
        $amount = trim((string) ($input['amount'] ?? ''));
        if ($amount === '') {
            $amount = in_array($case, ['payment_insufficient_funds', 'payment_daily_limit'], true) ? '999999999' : '1000';
        }
        $partyId = $case === 'payment_invalid_party'
            ? 'invalid-party'
            : $this->phoneNumber($input['phone_number'] ?? '');
        $payload = [
            'amount' => $this->amount($amount),
            'currency' => $this->currency($input['currency'] ?? ''),
            'externalId' => $reference,
            $partyField => ['partyIdType' => 'MSISDN', 'partyId' => $partyId],
            'payerMessage' => $this->message(trim((string) ($input['message'] ?? '')) ?: ('SIT ' . $case)),
            'payeeNote' => $this->message(trim((string) ($input['message'] ?? '')) ?: ('SIT ' . $case)),
        ];
        if ($case === 'payment_missing_amount') {
            unset($payload['amount']);
        }
        if ($case === 'payment_missing_currency') {
            unset($payload['currency']);
        }

        $callbackUrl = trim((string) ($input['callback_url'] ?? ''));
        if ($callbackUrl === '') {
            $callbackUrl = $this->callbackUrl(false);
        }
        if (in_array($case, ['payment_https_callback', 'payment_http_callback'], true)) {
            $scheme = $case === 'payment_https_callback' ? 'https://' : 'http://';
            if (!str_starts_with(strtolower($callbackUrl), $scheme)) {
                throw new MomoPayException('Enter a public ' . strtoupper(rtrim($scheme, ':/')) . ' callback URL for this SIT case.', 422);
            }
        }
        if (filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            $payload['callbackUrl'] = $callbackUrl;
        }

        $endpoint = $product === 'collection' ? '/collection/v1_0/requesttopay' : '/disbursement/v1_0/transfer';
        $response = $this->authorizedWithOverrides(
            $product,
            'sit_' . $product . '_' . $case,
            'POST',
            $endpoint,
            $payload,
            $providerReference,
            $this->sitRequestOverrides($case),
        );
        $this->recordTransaction([
            'reference' => $reference,
            'provider_reference' => $providerReference,
            'product' => $product,
            'operation' => 'sit_' . $case,
            'status' => $this->providerStatus($response),
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'phone_last4' => substr((string) $partyId, -4),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);

        return [
            'case' => $case,
            'product' => $product,
            'reference' => $reference,
            'provider_reference' => $providerReference,
            'provider_response' => $this->providerBody($response),
        ];
    }

    /** Query a known or deliberately unknown MTN transaction UUID. */
    private function sitStatus(string $product, string $case, array $input): array
    {
        $providerReference = $this->sitProviderReference($input, $case === 'status_valid');
        $endpoint = $product === 'collection'
            ? '/collection/v1_0/requesttopay/' . rawurlencode($providerReference)
            : '/disbursement/v1_0/transfer/' . rawurlencode($providerReference);
        $response = $this->authorizedWithOverrides(
            $product,
            'sit_' . $product . '_' . $case,
            'GET',
            $endpoint,
            null,
            '',
            $this->sitRequestOverrides($case),
        );

        return [
            'case' => $case,
            'product' => $product,
            'provider_reference' => $providerReference,
            'provider_response' => $this->providerBody($response),
        ];
    }

    /** Exercise account-holder validation cases with constrained invalid values. */
    private function sitAccountHolder(string $product, string $case, array $input): array
    {
        $query = strtolower(trim((string) ($input['query'] ?? 'active')));
        if (!in_array($query, ['active', 'basicuserinfo'], true)) {
            throw new MomoPayException('Choose active or basic user information.', 422);
        }
        $partyType = $case === 'account_invalid_type' ? 'INVALID' : 'MSISDN';
        $partyId = $case === 'account_invalid_id' ? 'invalid-account' : $this->phoneNumber($input['phone_number'] ?? '');
        $response = $this->authorizedWithOverrides(
            $product,
            'sit_' . $product . '_' . $case,
            'GET',
            '/' . $product . '/v1_0/accountholder/' . rawurlencode($partyType) . '/' . rawurlencode($partyId) . '/' . $query,
            null,
            '',
            $this->sitRequestOverrides($case),
        );

        return [
            'case' => $case,
            'product' => $product,
            'query' => $query,
            'provider_response' => $this->providerBody($response),
        ];
    }

    /** Retrieve the exact response for a balance case supported by the MTN endpoint. */
    private function sitBalance(string $product, string $case): array
    {
        $response = $this->authorizedWithOverrides(
            $product,
            'sit_' . $product . '_' . $case,
            'GET',
            '/' . $product . '/v1_0/account/balance',
            null,
            '',
            $this->sitRequestOverrides($case),
        );

        return [
            'case' => $case,
            'product' => $product,
            'provider_response' => $this->providerBody($response),
        ];
    }

    private function submit(string $product, string $operation, string $endpoint, array $input, string $partyField): array
    {
        $reference = $this->reference($input['reference'] ?? '');
        $providerReference = $this->uuid();
        $phone = $this->phoneNumber($input['phone_number'] ?? '');
        $message = $this->message($input['message'] ?? '');
        $payload = [
            'amount' => $this->amount($input['amount'] ?? ''),
            'currency' => $this->currency($input['currency'] ?? ''),
            'externalId' => $reference,
            $partyField => ['partyIdType' => 'MSISDN', 'partyId' => $phone],
            'payerMessage' => $message,
            'payeeNote' => $message,
        ];
        $callback = $this->callbackUrl(false);
        if (filter_var($callback, FILTER_VALIDATE_URL)) {
            $payload['callbackUrl'] = $callback;
        }
        $response = $this->authorized($product, $operation, 'POST', $endpoint, $payload, $providerReference);
        $this->recordTransaction([
            'reference' => $reference,
            'provider_reference' => $providerReference,
            'product' => $product,
            'operation' => $operation,
            'status' => $this->providerStatus($response),
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'phone_last4' => substr($phone, -4),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);

        return [
            'reference' => $reference,
            'provider_reference' => $providerReference,
            'provider_response' => $this->providerBody($response),
        ];
    }

    /** Get a product token, keeping the token out of the returned dashboard/API data. */
    private function token(string $product, ?string $operation = null, array $overrides = []): array
    {
        $this->requireProduct($product);
        $user = (string) ($overrides['api_user'] ?? $this->config($this->key($product, 'API_USER')));
        $apiKey = (string) ($overrides['api_key'] ?? $this->config($this->key($product, 'API_KEY')));
        $subscriptionKey = (string) ($overrides['subscription_key'] ?? $this->config($this->key($product, 'SUBSCRIPTION_KEY')));
        $response = $this->http($operation ?? ($product . '_token'), 'POST', $this->apiUrl('/' . $product . '/token/'), [
            'Accept: application/json',
            'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
            'Authorization: Basic ' . base64_encode($user . ':' . $apiKey),
        ]);
        if (trim((string) ($response['access_token'] ?? '')) === '') {
            throw new MomoPayException('MTN did not return an access token.', 502);
        }
        return $response;
    }

    private function authorized(string $product, string $operation, string $method, string $endpoint, ?array $payload = null, string $providerReference = ''): array
    {
        return $this->authorizedWithOverrides($product, $operation, $method, $endpoint, $payload, $providerReference);
    }

    /** Send an authorised request, optionally using a sandbox-only SIT mutation. */
    private function authorizedWithOverrides(string $product, string $operation, string $method, string $endpoint, ?array $payload = null, string $providerReference = '', array $overrides = []): array
    {
        $this->requireProduct($product);
        $token = array_key_exists('access_token', $overrides)
            ? ['access_token' => (string) $overrides['access_token']]
            : $this->token($product, $operation . '_token');
        $headers = [
            'Accept: application/json',
            'Ocp-Apim-Subscription-Key: ' . (string) ($overrides['subscription_key'] ?? $this->config($this->key($product, 'SUBSCRIPTION_KEY'))),
            'X-Target-Environment: ' . (string) ($overrides['target_environment'] ?? $this->targetEnvironment()),
            'Authorization: Bearer ' . $token['access_token'],
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($providerReference !== '') {
            $headers[] = 'X-Reference-Id: ' . $providerReference;
        }
        return $this->http($operation, $method, $this->apiUrl($endpoint), $headers, $payload);
    }

    /** Send one MTN request and persist the provider's returned HTTP body. */
    private function http(string $operation, string $method, string $url, array $headers, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new MomoPayException('The PHP cURL extension is required for MoMo Pay requests.', 503);
        }
        $needsEmptyPostBody = strtoupper($method) === 'POST' && $payload === null;
        $body = $payload === null ? ($needsEmptyPostBody ? '' : null) : $this->encodeJson($payload);
        if ($needsEmptyPostBody && !$this->hasHeader($headers, 'Content-Length')) {
            // MTN's token gateway rejects a bodyless POST unless its zero-byte
            // length is declared (HTTP 411). Sending the empty payload also
            // makes cURL use a regular POST instead of a bodyless custom verb.
            $headers[] = 'Content-Length: 0';
        }
        $sitLabel = str_starts_with($operation, 'sit_') ? ' [SIT: ' . $operation . ']' : '';
        $this->activity($operation . '_request', 'MoMo Pay request sent: ' . $method . ' ' . parse_url($url, PHP_URL_PATH) . '.' . $sitLabel, [
            'operation' => $operation,
            'method' => $method,
            'url' => $url,
            'headers' => $this->redactHeaders($headers),
            'body' => $payload,
        ]);
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()),
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false) {
            $this->activity($operation . '_response', 'MoMo Pay request failed before a response.', ['http_status' => $status, 'error' => $error]);
            throw new MomoPayException('MoMo Pay connection failed: ' . ($error ?: 'unknown transport error'), 502);
        }
        $decoded = trim($raw) === '' ? [] : json_decode($raw, true);
        $response = is_array($decoded) ? $decoded : ['raw_response' => $raw];
        $response['http_status'] = $status;
        $this->activity($operation . '_response', 'MoMo Pay response received (HTTP ' . $status . ').' . $sitLabel, [
            'source' => 'MTN MoMo API',
            'operation' => $operation,
            'http_status' => $status,
            'raw_body' => $this->redactProviderBody($raw),
        ]);
        if ($status < 200 || $status >= 300) {
            $message = $status === 500 && strtolower((string) ($response['error'] ?? '')) === 'login_failed'
                ? 'MTN rejected the API user and API key pair. Use the matching credentials provisioned for this product and environment.'
                : (string) ($response['message'] ?? $response['error_description'] ?? $response['reason'] ?? $response['error'] ?? 'MTN rejected the request.');
            throw new MomoPayException($message . ' (HTTP ' . $status . ')', 502);
        }
        return $response;
    }

    private function recordTransaction(array $record): void
    {
        $this->store->transaction(function (array &$state) use ($record): void {
            $transactions = is_array($state['momopay_transactions'] ?? null) ? $state['momopay_transactions'] : [];
            $transactions[$record['reference']] = $record;
            $state['momopay_transactions'] = $transactions;
        });
    }

    private function transaction(string $reference): array
    {
        return $this->store->transaction(function (array $state) use ($reference): array {
            $transactions = is_array($state['momopay_transactions'] ?? null) ? $state['momopay_transactions'] : [];
            return is_array($transactions[$reference] ?? null) ? $transactions[$reference] : [];
        }, false);
    }

    /** Store only a status supplied by MTN; an accepted request has no final provider status yet. */
    private function updateTransaction(string $reference, array $response, ?string $status): void
    {
        if (!PaymentReference::isValid($reference)) {
            return;
        }
        $this->store->transaction(function (array &$state) use ($reference, $response, $status): void {
            if (!is_array($state['momopay_transactions'][$reference] ?? null)) {
                return;
            }
            if ($status !== null) {
                $state['momopay_transactions'][$reference]['status'] = $status;
            }
            $state['momopay_transactions'][$reference]['provider_status'] = $this->redact($response);
            $state['momopay_transactions'][$reference]['updated_at'] = gmdate('c');
        });
    }

    private function updateTransactionByProviderReference(string $providerReference, array $response, string $status): void
    {
        $this->store->transaction(function (array &$state) use ($providerReference, $response, $status): void {
            foreach (($state['momopay_transactions'] ?? []) as $reference => $transaction) {
                if (($transaction['provider_reference'] ?? '') === $providerReference) {
                    $state['momopay_transactions'][$reference]['status'] = $status;
                    $state['momopay_transactions'][$reference]['provider_status'] = $this->redact($response);
                    $state['momopay_transactions'][$reference]['updated_at'] = gmdate('c');
                }
            }
        });
    }

    private function product(mixed $value): string
    {
        $product = strtolower(trim((string) $value));
        if (!in_array($product, self::PRODUCTS, true)) {
            throw new MomoPayException('Choose Collection or Disbursement.', 422);
        }
        return $product;
    }

    /** Remove transport metadata so the UI can show only fields returned by MTN. */
    private function providerBody(array $response): array
    {
        unset($response['http_status']);
        return $response;
    }

    /** Read an MTN transaction status without inventing one when the provider omitted it. */
    private function providerStatus(array $response): ?string
    {
        $status = $this->providerBody($response)['status'] ?? null;
        if (!is_scalar($status) || trim((string) $status) === '') {
            return null;
        }
        return strtoupper(trim((string) $status));
    }

    /** Validate the finite set of sandbox scenarios documented in the SIT workbook. */
    private function sitCase(mixed $value): string
    {
        $case = strtolower(trim((string) $value));
        $cases = [
            'auth_invalid_subscription', 'auth_invalid_api_key', 'auth_valid', 'auth_second_token',
            'payment_approved', 'payment_rejected', 'payment_timeout', 'payment_duplicate_reference',
            'payment_missing_amount', 'payment_missing_currency', 'payment_insufficient_funds', 'payment_invalid_party', 'payment_daily_limit',
            'payment_invalid_subscription', 'payment_invalid_target', 'payment_invalid_token',
            'payment_https_callback', 'payment_http_callback',
            'status_valid', 'status_invalid_reference', 'status_invalid_subscription', 'status_invalid_target', 'status_invalid_token',
            'account_valid', 'account_invalid_type', 'account_invalid_id', 'account_invalid_subscription', 'account_invalid_target', 'account_invalid_token',
            'balance_valid', 'balance_invalid_subscription', 'balance_invalid_target', 'balance_invalid_token',
        ];
        if (!in_array($case, $cases, true)) {
            throw new MomoPayException('Choose a supported MoMo SIT case.', 422);
        }
        return $case;
    }

    /** Apply only the deliberate header mutations required by a negative SIT case. */
    private function sitRequestOverrides(string $case): array
    {
        return match ($case) {
            'payment_invalid_subscription', 'status_invalid_subscription', 'account_invalid_subscription', 'balance_invalid_subscription' => ['subscription_key' => 'invalid-sit-subscription-key'],
            'payment_invalid_target', 'status_invalid_target', 'account_invalid_target', 'balance_invalid_target' => ['target_environment' => 'invalid-sit-environment'],
            'payment_invalid_token', 'status_invalid_token', 'account_invalid_token', 'balance_invalid_token' => ['access_token' => 'invalid-sit-bearer-token'],
            default => [],
        };
    }

    /** Read an MTN request UUID from the tester or create one for an invalid-reference case. */
    private function sitProviderReference(array $input, bool $required = false): string
    {
        $reference = trim((string) ($input['provider_reference'] ?? ''));
        if ($reference === '') {
            if ($required) {
                throw new MomoPayException('Enter the MTN UUID from the earlier request for this SIT case.', 422);
            }
            return $this->uuid();
        }
        if (!$this->isUuid($reference)) {
            throw new MomoPayException('Use an MTN request UUID v4 for this SIT case.', 422);
        }
        return $reference;
    }

    private function requireProduct(string $product): void
    {
        $this->apiUrl('');
        foreach (['SUBSCRIPTION_KEY', 'API_USER', 'API_KEY'] as $suffix) {
            $key = $this->key($product, $suffix);
            if ($this->config($key) === '') {
                throw new MomoPayException('Set ' . $key . ' in .env before using MoMo Pay.', 503);
            }
        }
        $apiUserKey = $this->key($product, 'API_USER');
        if (!$this->isUuid($this->config($apiUserKey))) {
            throw new MomoPayException($apiUserKey . ' must be the UUID v4 created as the MTN API user for this product.', 503);
        }
    }

    private function productConfigured(string $product): bool
    {
        return $this->config('MOMOPAY_BASE_URL') !== ''
            && $this->config($this->key($product, 'SUBSCRIPTION_KEY')) !== ''
            && $this->isUuid($this->config($this->key($product, 'API_USER')))
            && $this->config($this->key($product, 'API_KEY')) !== '';
    }

    private function key(string $product, string $suffix): string
    {
        return 'MOMOPAY_' . strtoupper($product) . '_' . $suffix;
    }

    private function apiUrl(string $path): string
    {
        $base = rtrim($this->config('MOMOPAY_BASE_URL'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($base), 'https://')) {
            throw new MomoPayException('MOMOPAY_BASE_URL must be a valid HTTPS URL.', 503);
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function targetEnvironment(): string
    {
        $environment = strtolower($this->config('MOMOPAY_TARGET_ENVIRONMENT') ?: 'sandbox');
        if (!preg_match('/^[a-z0-9-]{2,50}$/', $environment)) {
            throw new MomoPayException('MOMOPAY_TARGET_ENVIRONMENT is not valid.', 503);
        }
        return $environment;
    }

    private function callbackUrl(bool $required): string
    {
        $configured = $this->config('MOMOPAY_CALLBACK_URL');
        $url = $configured !== '' ? $configured : rtrim($this->config('APP_URL'), '/') . '/webhooks/momopay';
        if ($required && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new MomoPayException('Set MOMOPAY_CALLBACK_URL or APP_URL to a public callback URL.', 503);
        }
        return $url;
    }

    private function reference(mixed $value): string
    {
        $reference = strtoupper(trim((string) $value));
        if (!PaymentReference::isValid($reference)) {
            throw new MomoPayException('Reference must be a 10-character PMT ID.', 422);
        }
        return $reference;
    }

    private function amount(mixed $value): string
    {
        $amount = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            throw new MomoPayException('Amount must be greater than zero.', 422);
        }
        return $amount;
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value) ?: $this->defaultCurrency());
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new MomoPayException('Use a three-letter currency code.', 422);
        }
        return $currency;
    }

    private function defaultCurrency(): string
    {
        return strtoupper($this->config('MOMOPAY_DEFAULT_CURRENCY') ?: 'UGX');
    }

    private function phoneNumber(mixed $value): string
    {
        $phone = preg_replace('/[\s+()-]/', '', trim((string) $value)) ?? '';
        if (!preg_match('/^[0-9]{7,18}$/', $phone)) {
            throw new MomoPayException('Use a valid phone number with country code.', 422);
        }
        return $phone;
    }

    private function message(mixed $value): string
    {
        $message = trim((string) $value);
        if (strlen($message) < 2 || strlen($message) > 160) {
            throw new MomoPayException('Message must contain between 2 and 160 characters.', 422);
        }
        return $message;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value) === 1;
    }

    private function timeout(): int
    {
        $timeout = filter_var($this->config('MOMOPAY_TIMEOUT_SECONDS') ?: '30', FILTER_VALIDATE_INT);
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
        } catch (\JsonException) {
            throw new MomoPayException('Unable to encode the MoMo Pay request.', 422);
        }
    }

    private function activity(string $type, string $message, array $context = []): void
    {
        $this->store->transaction(function (array &$state) use ($type, $message, $context): void {
            $entries = is_array($state['momopay_activity'] ?? null) ? $state['momopay_activity'] : [];
            array_unshift($entries, [
                'type' => $type,
                'message' => $message,
                'at' => gmdate('c'),
                'context' => $this->redact($context),
            ]);
            $state['momopay_activity'] = array_slice($entries, 0, 150);
        });
    }

    private function redactHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $safe[$name] = preg_match('/authorization|key|secret|token/i', $name) ? '[redacted]' : trim($value);
        }
        return $safe;
    }

    /** Keep the provider body intact where safe, while never persisting secrets or customer numbers. */
    private function redactProviderBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }

        $redacted = $this->redact($decoded);
        if ($redacted === $decoded) {
            return $body;
        }
        return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[response could not be redacted]';
    }

    /** Return whether the request already includes the given HTTP header. */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            [$headerName] = explode(':', $header, 2);
            if (strcasecmp(trim($headerName), $name) === 0) {
                return true;
            }
        }
        return false;
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
        if ($key !== null && preg_match('/authorization|key|secret|token|password|signature/i', $key)) {
            return '[redacted]';
        }
        if ($key !== null && preg_match('/phone|msisdn|partyid|account/i', $key) && is_scalar($value)) {
            $digits = preg_replace('/\D/', '', (string) $value) ?? '';
            return strlen($digits) > 4 ? str_repeat('*', strlen($digits) - 4) . substr($digits, -4) : '[redacted]';
        }
        return $value;
    }
}
