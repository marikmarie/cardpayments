<?php
declare(strict_types=1);

namespace Pegasus;

use App\Config;
use App\Store;

/** Hosted PegPay Web card-collection form and signed browser-return handling. */
final class PegasusWebGateway
{
    private PegasusCardLogger $logger;
    private PegasusCardRepository $cards;
    private ?array $lastStatusRequest = null;

    public function __construct(private Store $store)
    {
        $this->logger = new PegasusCardLogger($store);
        $this->cards = new PegasusCardRepository($store);
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
            // This reference becomes part of a public hosted-checkout URL. Keep it
            // unguessable as well as unique so only the customer who receives the
            // checkout URL can start that payment session.
            'id' => 'CARD-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(12))),
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
        if (!$this->cards->save($record)) $this->saveLegacy($record);
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
    public function logCheckoutRequest(array $input): void
    {
        $this->logger->checkoutReceived($input);
    }

    public function logReturnRequest(array $input): void
    {
        $this->logger->returnReceived($input);
    }

    /** Query PegPay Web's QueryStatus.aspx endpoint for a locally created card collection. */
    public function queryStatus(array $input): array
    {
        $reference = $input['vendor_transaction_id'] ?? '';
        $id = is_scalar($reference) ? trim((string) $reference) : '';
        $this->lastStatusRequest = null;
        $this->logger->statusRequested($id);
        try {
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
                throw new \InvalidArgumentException('Enter a valid card collection reference.');
            }
            $record = $this->find($id);
            if (!$record) throw new \InvalidArgumentException('Choose a card collection created in this dashboard.');
            $this->requireStatusQueryInterval($record);
            $this->requireStatusConfiguration();

            $endpoint = $this->statusEndpoint();
            $query = $this->statusQuery($id);
            $this->lastStatusRequest = [
                'method' => 'GET',
                'url' => $this->statusQueryUrl($endpoint, $this->redactedStatusQuery($query)),
                'password_format' => 'HMAC-SHA256(PEGASUS_WEB_PASSWORD, PEGASUS_WEB_SECRET_CODE)',
            ];
            $this->update($id, ['status_query_requested_at' => gmdate('c')]);
            $this->logger->statusQuerySent($id, $this->lastStatusRequest['url'], $query);
            [$response, $httpStatus] = $this->sendStatusQuery($endpoint, $query);

            $status = strtoupper(trim($this->responseValue($response, ['Status', 'STATUS'])));
            $reason = trim($this->responseValue($response, ['Reason', 'REASON']));
            $vendorId = trim($this->responseValue($response, ['VendorID', 'VendorTranId', 'VENDOR_TRANID']));
            $transactionId = trim($this->responseValue($response, ['TranID', 'TransactionId', 'TRANSACTION_ID']));
            $signature = trim($this->responseValue($response, ['DigitalSignature', 'DIGITAL_SIGNATURE']));
            $signatureValid = $status !== '' && $reason !== '' && $vendorId === $id && $signature !== ''
                && hash_equals($this->sign($status . $reason . $vendorId), strtolower($signature));
            $outcome = $this->status($status);
            $this->logger->statusReturned($id, $this->lastStatusRequest['url'], $httpStatus, $response, $signatureValid ? $outcome : 'FAILED', $signatureValid);

            if ($httpStatus >= 400) throw new \RuntimeException('PegPay Web returned HTTP ' . $httpStatus . ' while checking this collection.');
            if ($status === '' || $reason === '' || $vendorId === '') throw new \RuntimeException('PegPay Web returned an invalid status response.');
            if ($vendorId !== $id) throw new \RuntimeException('PegPay Web returned a status for a different card collection.');
            if (!$signatureValid) throw new \RuntimeException('PegPay Web status response could not be verified.');

            $record = $this->update($id, [
                'status' => $outcome,
                'gateway_status' => $status,
                'gateway_reason' => $reason,
                'pegpay_transaction_id' => $transactionId,
                'response_signature_valid' => true,
                'status_query_response' => $this->safeStatusResponse($response),
                'status_query_http_status' => $httpStatus,
                'status_queried_at' => gmdate('c'),
            ]);
            return [
                'record' => $record,
                'provider_response' => $this->safeStatusResponse($response),
                'http_status' => $httpStatus,
                'status_request' => $this->lastStatusRequest,
            ];
        } catch (\Throwable $e) {
            $this->logger->statusFailed($id, $e, $this->lastStatusRequest['url'] ?? null);
            throw $e;
        }
    }

    /** Returns the redacted QueryStatus request generated during the latest check. */
    public function lastStatusRequest(): ?array
    {
        return $this->lastStatusRequest;
    }

    public function logFailure(string $operation, \Throwable $error, array $input = []): void
    {
        $this->logger->error($operation, $error, $input);
    }

    /** @return array{writable: bool, entries: list<array>} */
    public function logDetails(): array
    {
        return $this->logger->details();
    }

    /** @return list<array> */
    public function recent(): array
    {
        $stored = $this->cards->recent();
        $legacy = array_values($this->store->read('pegasus_card_collections'));
        $rows = $stored === null ? $legacy : $this->mergeCollections($legacy, $stored);
        usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return array_slice($rows, 0, 8);
    }

    public function gatewayUrl(): string
    {
        return $this->config()['gateway_url'];
    }

    /** Retrieve a locally-created card collection without exposing gateway secrets. */
    public function collection(string $id): array
    {
        $id = trim($id);
        if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
            throw new \InvalidArgumentException('Enter a valid card collection reference.');
        }
        $record = $this->find($id);
        if (!$record) throw new \InvalidArgumentException('Card collection was not found.');
        return $record;
    }

    /**
     * A short-lived-looking, opaque-enough hosted route used by mobile and SPA
     * clients. It renders the signed PegPay HTML form server-side, so neither
     * client needs (or sees) PegPay credentials or signatures.
     */
    public function checkoutUrl(array $collection): string
    {
        $baseUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \LogicException('Set APP_URL to a complete URL before creating card collections.');
        }
        return $baseUrl . '/pegasus-card/pay/' . rawurlencode((string) $collection['id']);
    }

    private function requireConfiguration(): void
    {
        if (!$this->configured()) throw new \LogicException('PegPay Card Collections is not configured. Add the PegPay Web credentials to .env.');
        $config = $this->config();
        if (!filter_var($config['gateway_url'], FILTER_VALIDATE_URL) || !str_starts_with($config['gateway_url'], 'https://')) {
            throw new \LogicException('PEGASUS_WEB_GATEWAY_URL must be an HTTPS URL.');
        }
    }

    private function requireStatusConfiguration(): void
    {
        foreach (['status_url', 'vendor_code', 'password', 'secret_code', 'merchant_code'] as $key) {
            if ($this->config()[$key] === '') {
                throw new \LogicException('PegPay Web status is not configured. Add PEGASUS_WEB_STATUS_URL and the PegPay Web credentials to .env.');
            }
        }
        $endpoint = $this->statusEndpoint();
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
            throw new \LogicException('PEGASUS_WEB_STATUS_URL must be an HTTPS QueryStatus.aspx URL.');
        }
        $endpointPath = strtolower((string) parse_url($endpoint, PHP_URL_PATH));
        if (!str_ends_with($endpointPath, '/querystatus.aspx')) {
            throw new \LogicException('PEGASUS_WEB_STATUS_URL must point to the PegPay Web QueryStatus.aspx endpoint.');
        }
    }

    private function config(): array
    {
        return [
            'gateway_url' => trim((string) Config::get('PEGASUS_WEB_GATEWAY_URL', '')),
            'status_url' => trim((string) Config::get('PEGASUS_WEB_STATUS_URL', '')),
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
        $record = $this->cards->find($id);
        if ($record !== null) return $record;
        return $this->store->transaction(fn(array $state): ?array => $state['pegasus_card_collections'][$id] ?? null, false);
    }

    private function update(string $id, array $changes): array
    {
        $record = $this->find($id);
        if (!$record) throw new \RuntimeException('Card collection was not found.');
        $record = array_merge($record, $changes, ['updated_at' => gmdate('c')]);
        if ($this->cards->save($record)) return $record;
        $this->saveLegacy($record);
        return $record;
    }

    private function saveLegacy(array $record): void
    {
        $this->store->transaction(function (array &$state) use ($record): void {
            $state['pegasus_card_collections'][$record['id']] = $record;
        });
    }

    /** Merge existing JSON cards with MySQL cards during the one-time migration. */
    private function mergeCollections(array $legacy, array $stored): array
    {
        $collections = [];
        foreach ($legacy as $record) $collections[$record['id']] = $record;
        foreach ($stored as $record) $collections[$record['id']] = $record;
        return array_values($collections);
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
        foreach ($keys as $key) if (isset($input[$key]) && is_scalar($input[$key])) return (string) $input[$key];
        return '';
    }

    /** PegPay Web documents this exact query format; it is separate from GetTransactionDetails. */
    private function statusQuery(string $vendorTransactionId): array
    {
        $config = $this->config();
        return [
            'MerchantId' => $config['merchant_code'],
            'VendorCode' => $config['vendor_code'],
            'Pswd' => $this->sign($config['password']),
            'VendorTranId' => $vendorTransactionId,
        ];
    }

    private function statusEndpoint(): string
    {
        return rtrim($this->config()['status_url'], '?&');
    }

    private function statusQueryUrl(string $endpoint, array $query): string
    {
        return $endpoint
            . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** A log-safe representation of the documented query fields. */
    private function redactedStatusQuery(array $query): array
    {
        $query['Pswd'] = 'REDACTED_HMAC_SHA256';
        return $query;
    }

    /** @return array{0: array, 1: int} */
    private function sendStatusQuery(string $endpoint, array $query): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL is required for PegPay Web status checks.');
        $url = $this->statusQueryUrl($endpoint, $query);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: text/plain, application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false) throw new \RuntimeException('PegPay Web status transport error: ' . $error);

        parse_str(trim((string) $raw), $response);
        if ($response === []) $response = ['_invalid_response' => true];
        return [$response, $httpStatus];
    }

    private function requireStatusQueryInterval(array $record): void
    {
        if (strtoupper((string) ($record['status'] ?? 'PENDING')) !== 'PENDING') return;
        $lastQuery = strtotime((string) ($record['status_query_requested_at'] ?? ''));
        if ($lastQuery !== false && time() - $lastQuery < 5) {
            throw new \RuntimeException('Wait at least 5 seconds before checking a pending card collection again.');
        }
    }

    private function safeStatusResponse(array $response): array
    {
        return array_filter([
            'Status' => $this->responseValue($response, ['Status', 'STATUS']),
            'Reason' => $this->responseValue($response, ['Reason', 'REASON']),
            'TranID' => $this->responseValue($response, ['TranID', 'TransactionId', 'TRANSACTION_ID']),
            'VendorID' => $this->responseValue($response, ['VendorID', 'VendorTranId', 'VENDOR_TRANID']),
            'DigitalSignature' => $this->responseValue($response, ['DigitalSignature', 'DIGITAL_SIGNATURE']) === '' ? null : '[redacted]',
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
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
