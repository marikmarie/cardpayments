<?php
// Card module CyberSource HTTP client.

class CyberSource
{
    /** Host (no scheme) per environment — used for the Host header and Signature. */
    private const HOSTS = [
        'sandbox' => 'apitest.cybersource.com',
        'live' => 'api.cybersource.com',
    ];

    private string $host;          // resolved host for the environment
    private string $merchantId;    // v-c-merchant-id
    private string $keyId;         // shared-secret key serial number (Business Center)
    private string $secretKey;     // base64 shared secret
    private int $timeout;

    private function __construct(array $cfg)
    {
        $this->host = self::HOSTS[$cfg['environment']];
        $this->merchantId = $cfg['merchant_id'];
        $this->keyId = $cfg['key_id'];
        $this->secretKey = $cfg['secret_key'];
        $this->timeout = (int) ($cfg['timeout'] ?? 30);
    }


    public static function init(array $cfg): self
    {
        $cfg['environment'] = strtolower((string) ($cfg['environment'] ?? 'live'));
        if ($cfg['environment'] === 'production') {
            $cfg['environment'] = 'live';
        }

        if (!isset(self::HOSTS[$cfg['environment']])) {
            throw new InvalidArgumentException("environment must be 'sandbox' or 'live'");
        }
        foreach (['merchant_id', 'key_id', 'secret_key'] as $req) {
            if (empty($cfg[$req])) {
                throw new InvalidArgumentException("Missing required config: {$req}");
            }
        }
        return new self($cfg);
    }

    public function createPaymentLink(array $inv): array
    {
        $body = [
            'customerInformation' => $this->prune([
                'name' => $inv['customerName'] ?? null,
                'email' => $inv['customerEmail'] ?? null,
            ]),
            'invoiceInformation' => $this->prune([
                'invoiceNumber' => $inv['invoiceNumber'],
                'description' => $inv['description'] ?? null,
                'dueDate' => $inv['dueDate'] ?? null,
                'allowPartialPayments' => $inv['allowPartial'] ?? false,
                // Create the secure checkout URL first. If the caller asked
                // for email delivery, the documented /delivery endpoint is
                // called immediately afterwards by the application service.
                'deliveryMode' => 'none',
            ]),
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $inv['amount'],
                    'currency' => $inv['currency'],
                ],
            ],
        ];

        $result = $this->request('POST', '/invoicing/v2/invoices', $body);

        // surface the link at the top level for convenience
        $result['paymentLink'] =
            $result['data']['invoiceInformation']['paymentLink'] ?? null;

        return $result;
    }

    /** Email an existing (draft) invoice to the customer. */
    public function sendInvoice(string $invoiceId): array
    {
        return $this->request('POST', "/invoicing/v2/invoices/{$invoiceId}/delivery", []);
    }

    /** Fetch an invoice (status, balance, paymentLink). */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', "/invoicing/v2/invoices/{$invoiceId}");
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $method = strtoupper($method);
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $operation = $method === 'GET' && preg_match('#^/invoicing/v2/invoices/[^/]+$#', $path)
            ? 'invoice_status'
            : 'api_request';
        // CyberSource follow-on actions such as invoice delivery require an
        // empty JSON object ({}) rather than an empty array ([]).
        $wirePayload = $hasBody && ($payload ?? []) === [] ? new stdClass() : $payload;
        $body = $hasBody ? json_encode($wirePayload, JSON_UNESCAPED_SLASHES) : '';

        $headers = $this->buildHeaders($method, $path, $body, $hasBody);

        // Keep operational logging limited to the request and response. The
        // signature itself is never written to disk.
        $this->log('REQUEST', [
            'operation' => $operation,
            'method' => $method,
            'url' => "https://{$this->host}{$path}",
            'headers' => $this->safeHeaders($headers),
            'body' => $this->redact($wirePayload),
        ]);

        $ch = curl_init("https://{$this->host}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($hasBody) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $response = [
                'success' => false,
                'code' => 0,
                'status' => 'TRANSPORT_ERROR',
                'id' => null,
                'message' => "cURL error: {$curlErr}",
                'error' => 'TRANSPORT_ERROR',
                'data' => null,
                'raw' => null,
                'operation' => $operation,
                'invoice_status' => null,
            ];
            $this->log('RESPONSE', $response);
            return $response;
        }

        $normalized = $this->normalize($code, $raw);
        $normalized['operation'] = $operation;
        $normalized['invoice_status'] = $operation === 'invoice_status'
            ? ($normalized['data']['status'] ?? null)
            : null;

        $this->log('RESPONSE', $normalized);

        return $normalized;
    }

    private function log(string $type, $data): void
    {
        $directory = dirname(__DIR__) . '/storage';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        if (!is_writable($directory)) {
            error_log('CissyTech CyberSource logging unavailable: storage is not writable.');
            return;
        }
        $logFile = $directory . '/cybersource.log';

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'data' => $data
        ];

        $written = file_put_contents(
            $logFile,
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n",
            FILE_APPEND | LOCK_EX
        );
        if ($written === false) {
            error_log('CissyTech CyberSource logging unavailable: could not write storage/cybersource.log.');
        }
    }

    /** Keep request diagnostics useful without writing card data to disk. */
    private function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['number', 'securitycode'], true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }
        return $data;
    }

    private function safeHeaders(array $headers): array
    {
        return array_map(static function (string $header): string {
            return str_starts_with(strtolower($header), 'signature:')
                ? 'Signature: [redacted]'
                : $header;
        }, $headers);
    }


    /**
     * Build the signed header set. POST includes a Digest; GET does not.
     * Signing string order MUST match the `headers` list in the Signature header.
     */
    private function buildHeaders(string $method, string $path, string $body, bool $hasBody): array
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        // request-target is lowercase method + space + path.
        $requestTarget = strtolower($method) . ' ' . $path;

        $signed = ['host', 'v-c-date', 'request-target'];
        $values = [
            'host' => $this->host,
            'v-c-date' => $date,
            'request-target' => $requestTarget,
        ];

        $digest = null;
        if ($hasBody) {
            $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
            $signed[] = 'digest';
            $values['digest'] = $digest;
        }

        $signed[] = 'v-c-merchant-id';
        $values['v-c-merchant-id'] = $this->merchantId;

        // Build the signing string: "name: value" lines joined by \n
        $lines = [];
        foreach ($signed as $name) {
            $lines[] = $name . ': ' . $values[$name];
        }
        $signingString = implode("\n", $lines);

        $signature = $this->sign($signingString);

        $sigHeader = sprintf(
            'keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"',
            $this->keyId,
            implode(' ', $signed),
            $signature
        );

        $headers = [
            'Host: ' . $this->host,
            'Signature: ' . $sigHeader,
        ];
        if ($hasBody) {
            $headers[] = 'Digest: ' . $digest;
        }
        $headers[] = 'v-c-merchant-id: ' . $this->merchantId;
        $headers[] = 'v-c-date: ' . $date;
        $headers[] = 'Content-Type: application/json';

        return $headers;
    }

    /** HMAC-SHA256 of the canonical string using the decoded shared secret. */
    private function sign(string $signingString): string
    {
        $decodedSecret = base64_decode($this->secretKey, true);
        if ($decodedSecret === false) {
            throw new InvalidArgumentException('secret_key must be a valid Base64-encoded shared secret');
        }

        $rawHmac = hash_hmac('sha256', $signingString, $decodedSecret, true);
        return base64_encode($rawHmac);
    }

    private function normalize(int $code, string $raw): array
    {
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
        $errorInformation = is_array($data['errorInformation'] ?? null) ? $data['errorInformation'] : [];
        $ok = ($code >= 200 && $code < 300);
        $status = $data['status'] ?? null;
        $id = $data['id'] ?? null;
        $message = $data['message']
            ?? $errorInformation['message']
            ?? $status
            ?? ($raw !== '' ? $raw : null)
            ?? ($ok ? 'OK' : 'Request failed');
        $error = $ok ? null : ($data['reason'] ?? $errorInformation['reason'] ?? $message);
        return [
            'success' => $ok,
            'code' => $code,
            'status' => $status,
            'id' => $id,
            'message' => $message,
            'error' => $error,
            'data' => $data ?: null,
            'raw' => $raw,
        ];
    }

    /** Recursively drop null / "" values so we never send empty fields. */
    private function prune(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->prune($v);
                if ($arr[$k] === []) {
                    unset($arr[$k]);
                }
            } elseif ($v === null || $v === '') {
                unset($arr[$k]);
            }
        }
        return $arr;
    }

}
