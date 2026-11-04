<?php
declare(strict_types=1);

namespace CissyTech\Pegasus\Sdk;

/**
 * Stateless PegPay Web checkout and QueryStatus client.
 *
 * It builds the signed HTML form and verifies QueryStatus/return data. The
 * host application persists the collection and must wait five seconds between
 * queries while PegPay reports PENDING.
 */
final class PegasusWebClient
{
    private string $gatewayUrl;
    private string $statusUrl;
    private string $vendorCode;
    private string $password;
    private string $secretCode;
    private string $merchantCode;
    private int $timeoutSeconds;

    /** @var null|callable(string, string, array<int, string>, string, int): array{status: int, body: string} */
    private $transport;

    /**
     * @param null|callable(string, string, array<int, string>, string, int): array{status: int, body: string} $transport
     */
    public function __construct(
        string $gatewayUrl,
        string $statusUrl,
        string $vendorCode,
        string $password,
        string $secretCode,
        string $merchantCode,
        int $timeoutSeconds = 30,
        ?callable $transport = null,
    ) {
        $this->gatewayUrl = trim($gatewayUrl);
        $this->statusUrl = rtrim(trim($statusUrl), '?&');
        $this->vendorCode = trim($vendorCode);
        $this->password = $password;
        $this->secretCode = $secretCode;
        $this->merchantCode = trim($merchantCode);
        $this->timeoutSeconds = max(5, $timeoutSeconds);
        $this->transport = $transport;

        foreach (['gatewayUrl' => $this->gatewayUrl, 'statusUrl' => $this->statusUrl, 'vendorCode' => $this->vendorCode, 'password' => $this->password, 'secretCode' => $this->secretCode, 'merchantCode' => $this->merchantCode] as $field => $value) {
            if (trim($value) === '') throw new \InvalidArgumentException("PegPay Web {$field} is required.");
        }
        if (!filter_var($this->gatewayUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($this->gatewayUrl), 'https://')) {
            throw new \InvalidArgumentException('gatewayUrl must be an HTTPS PegPay Web URL.');
        }
        if (!filter_var($this->statusUrl, FILTER_VALIDATE_URL) || !str_ends_with(strtolower((string) parse_url($this->statusUrl, PHP_URL_PATH)), '/querystatus.aspx')) {
            throw new \InvalidArgumentException('statusUrl must be PegPay Web HTTPS QueryStatus.aspx URL.');
        }
    }

    /** Build the server-side fields for PegPay's hosted checkout form. */
    public function checkoutForm(array $parameters): array
    {
        $reference = $this->reference((string) ($parameters['vendor_transaction_id'] ?? $parameters['reference'] ?? ''));
        $amount = $this->amount($parameters['amount'] ?? '');
        $currency = strtoupper(trim((string) ($parameters['currency'] ?? 'UGX')));
        if (!in_array($currency, ['UGX', 'USD'], true)) throw new \InvalidArgumentException('currency must be UGX or USD.');
        $description = $this->text($parameters['description'] ?? '', 200, 'description');
        if ($description === '') throw new \InvalidArgumentException('description is required.');
        $returnUrl = trim((string) ($parameters['return_url'] ?? $parameters['returnUrl'] ?? ''));
        if (!filter_var($returnUrl, FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('return_url must be a complete URL.');
        $name = $this->text($parameters['customer_name'] ?? $parameters['customerName'] ?? '', 120, 'customer_name');
        $email = trim((string) ($parameters['customer_email'] ?? $parameters['customerEmail'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('customer_email must be valid when supplied.');

        $fields = [
            'VENDORCODE' => $this->vendorCode,
            'PASSWORD' => $this->hash($this->password),
            'VENDOR_TRANID' => $reference,
            'ITEM_TOTAL' => $amount,
            'ITEM_DESCRIPTION' => $description,
            'CURRENCY' => $currency,
            'RETURN_URL' => $returnUrl,
            'DIGITAL_SIGNATURE' => $this->hash($this->vendorCode . $this->merchantCode . $amount . $description . $currency . $returnUrl . $reference),
            'MERCHANTCODE' => $this->merchantCode,
        ];
        if ($email !== '') $fields['EMAILADDRESS'] = $email;
        if ($name !== '') $fields['NAME'] = $name;
        return ['url' => $this->gatewayUrl, 'method' => 'POST', 'fields' => $fields];
    }

    /** Return the documented QueryStatus request details for a caller-owned collection reference. */
    public function statusRequest(string $vendorTransactionId): array
    {
        $query = [
            'MerchantId' => $this->merchantCode,
            'VendorCode' => $this->vendorCode,
            'Pswd' => $this->hash($this->password),
            'VendorTranId' => $this->reference($vendorTransactionId),
        ];
        $url = $this->statusUrl . (str_contains($this->statusUrl, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return ['url' => $url, 'query' => $query];
    }

    /** Send QueryStatus.aspx and return its parsed response fields exactly as PegPay sent them. */
    public function queryStatus(string $vendorTransactionId): array
    {
        $request = $this->statusRequest($vendorTransactionId);
        try {
            $response = $this->transport
                ? ($this->transport)('GET', $request['url'], ['Accept: text/plain, application/x-www-form-urlencoded'], '', $this->timeoutSeconds)
                : $this->curl($request['url']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('PegPay Web status connection failed: ' . $e->getMessage(), 0, $e);
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 400) throw new \RuntimeException('PegPay Web returned HTTP ' . $status . ' while checking this collection.');
        parse_str(trim((string) ($response['body'] ?? '')), $parsed);
        if ($parsed === []) throw new \RuntimeException('PegPay Web returned an invalid status response.');
        return $parsed;
    }

    /** Verify a browser return or QueryStatus response for the supplied reference. */
    public function verifyResponse(array $response, string $expectedVendorTransactionId): bool
    {
        $status = strtoupper(trim($this->value($response, ['Status', 'STATUS'])));
        $reason = trim($this->value($response, ['Reason', 'REASON']));
        $vendorId = trim($this->value($response, ['VendorID', 'VendorTranId', 'VENDOR_TRANID']));
        $signature = trim($this->value($response, ['DigitalSignature', 'DIGITAL_SIGNATURE']));
        return $status !== '' && $reason !== '' && $vendorId === $this->reference($expectedVendorTransactionId)
            && $signature !== '' && hash_equals($this->hash($status . $reason . $vendorId), strtolower($signature));
    }

    /** Convert a PegPay response status to a simple status without storing it. */
    public static function paymentStatus(array $response): string
    {
        $status = strtoupper(trim((string) ($response['Status'] ?? $response['STATUS'] ?? '')));
        return match ($status) {
            'SUCCESS' => 'SUCCESS',
            'FAILED' => 'FAILED',
            default => 'PENDING',
        };
    }

    /** @return array{status: int, body: string} */
    private function curl(string $url): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL is required for PegPay Web status checks.');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: text/plain, application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false) throw new \RuntimeException($error ?: 'unknown cURL error.');
        return ['status' => $status, 'body' => $raw];
    }

    private function hash(string $value): string
    {
        if (!preg_match('/^[\x20-\x7E]*$/', $value)) throw new \InvalidArgumentException('PegPay signed fields must contain ASCII characters only.');
        return hash_hmac('sha256', $value, $this->secretCode);
    }

    private function reference(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^PMT[A-Z0-9]{7}$/', $value)) {
            throw new \InvalidArgumentException('vendor_transaction_id must start with PMT and contain exactly 10 letters or numbers.');
        }
        return $value;
    }

    private function amount(mixed $value): string
    {
        $amount = trim((string) $value);
        if (!preg_match('/^[1-9]\d*(?:\.\d{1,2})?$/', $amount)) throw new \InvalidArgumentException('amount must be positive with at most two decimal places.');
        return $amount;
    }

    private function text(mixed $value, int $length, string $field): string
    {
        $value = trim((string) $value);
        if (strlen($value) > $length || !preg_match('/^[\x20-\x7E]*$/', $value)) {
            throw new \InvalidArgumentException("{$field} must contain at most {$length} ASCII characters.");
        }
        return $value;
    }

    private function value(array $input, array $keys): string
    {
        foreach ($keys as $key) if (isset($input[$key]) && is_scalar($input[$key])) return (string) $input[$key];
        return '';
    }
}
