<?php

class CyberSource
{
    /** Host (no scheme) per environment — used for the Host header and Signature. */
    private const HOSTS = [
        'sandbox' => 'apitest.cybersource.com',
        'live' => 'api.cybersource.com',
    ];

    private string $mode;          // 'api' | 'payment_link'
    private string $environment;   // 'sandbox' | 'live'
    private string $host;          // resolved host for the environment
    private string $merchantId;    // v-c-merchant-id
    private string $keyId;         // shared-secret key serial number (Business Center)
    private string $secretKey;     // base64 shared secret
    private int $timeout;

    /** @var array Last raw response, for debugging. */
    private array $last = [];

    private function __construct(array $cfg)
    {
        $this->mode = $cfg['mode'];
        $this->environment = $cfg['environment'];
        $this->host = self::HOSTS[$this->environment];
        $this->merchantId = $cfg['merchant_id'];
        $this->keyId = $cfg['key_id'];
        $this->secretKey = $cfg['secret_key'];
        $this->timeout = (int) ($cfg['timeout'] ?? 30);
    }


    public static function init(array $cfg): self
    {
        $cfg['mode'] = $cfg['mode'] ?? 'api';
        $cfg['environment'] = $cfg['environment'] ?? 'sandbox';

        if (!in_array($cfg['mode'], ['api', 'payment_link'], true)) {
            throw new InvalidArgumentException("mode must be 'api' or 'payment_link'");
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

    // -------------------------------------------------------------------------
    // API mode — direct card processing  (/pts/v2/payments)
    // -------------------------------------------------------------------------

    public function authorize(array $order): array
    {
        $body = [
            'clientReferenceInformation' => [
                'code' => $order['reference'] ?? uniqid('ref_', true),
            ],
            'processingInformation' => [
                'capture' => (bool) ($order['capture'] ?? false),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $order['amount'],
                    'currency' => $order['currency'],
                ],
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $order['card']['number'],
                    'expirationMonth' => $order['card']['expirationMonth'],
                    'expirationYear' => $order['card']['expirationYear'],
                    'securityCode' => $order['card']['securityCode'] ?? null,
                    'type' => $order['card']['type'] ?? null,
                ],
            ],
        ];

        if (!empty($order['billTo'])) {
            $body['orderInformation']['billTo'] = $order['billTo'];
        }

        return $this->request('POST', '/pts/v2/payments', $this->prune($body));
    }

    /** Auth + capture in one shot. */
    public function sale(array $order): array
    {
        $order['capture'] = true;
        return $this->authorize($order);
    }

    /**
     * Capture a previously authorized payment (moves the funds).
     *
     * @param string $paymentId  transaction id returned by authorize()
     */
    public function capture(string $paymentId, array $order): array
    {
        $body = [
            'clientReferenceInformation' => [
                'code' => $order['reference'] ?? ('cap_' . $paymentId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $order['amount'],
                    'currency' => $order['currency'],
                ],
            ],
        ];
        return $this->request('POST', "/pts/v2/payments/{$paymentId}/captures", $body);
    }

    /**
     * Refund a captured payment (or a sale). Follow-on credit.
     *
     * @param string $captureOrPaymentId  id of the capture (or payment if it was a sale)
     */
    public function refund(string $captureOrPaymentId, array $order): array
    {
        $body = [
            'clientReferenceInformation' => [
                'code' => $order['reference'] ?? ('ref_' . $captureOrPaymentId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $order['amount'],
                    'currency' => $order['currency'],
                ],
            ],
        ];
        return $this->request('POST', "/pts/v2/payments/{$captureOrPaymentId}/refunds", $body);
    }


    public function void(string $id, ?string $reference = null): array
    {
        $body = [
            'clientReferenceInformation' => [
                'code' => $reference ?? ('void_' . $id),
            ],
        ];
        return $this->request('POST', "/pts/v2/payments/{$id}/voids", $body);
    }

    /**
     * Reverse an authorization before it is captured (releases the hold).
     */
    public function reverse(string $paymentId, array $order): array
    {
        $body = [
            'clientReferenceInformation' => [
                'code' => $order['reference'] ?? ('rev_' . $paymentId),
            ],
            'reversalInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $order['amount'],
                ],
            ],
        ];
        return $this->request('POST', "/pts/v2/payments/{$paymentId}/reversals", $body);
    }

    /** Fetch a transaction by id. */
    public function getTransaction(string $id): array
    {
        return $this->request('GET', "/tss/v2/transactions/{$id}");
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

    /** Cancel an invoice. */
    public function cancelInvoice(string $invoiceId): array
    {
        return $this->request('POST', "/invoicing/v2/invoices/{$invoiceId}/cancelation", []);
    }


    private function request(string $method, string $path, ?array $payload = null): array
    {
        $method = strtoupper($method);
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);
        // CyberSource follow-on actions such as invoice delivery require an
        // empty JSON object ({}) rather than an empty array ([]).
        $wirePayload = $hasBody && ($payload ?? []) === [] ? new stdClass() : $payload;
        $body = $hasBody ? json_encode($wirePayload, JSON_UNESCAPED_SLASHES) : '';

        $headers = $this->buildHeaders($method, $path, $body, $hasBody);

        // LOG REQUEST
        $this->log('REQUEST', [
            'method' => $method,
            'url' => "https://{$this->host}{$path}",
            'headers' => $headers,
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
        $curlNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $response = $this->envelope(
                false,
                0,
                'TRANSPORT_ERROR',
                null,
                null,
                null,
                "cURL error {$curlNo}: {$curlErr}"
            );
            $this->log('RESPONSE', $response);
            return $response;
        }

        $normalized = $this->normalize($code, $raw);

        // ✅ LOG NORMALIZED RESPONSE
        $this->log('RESPONSE', $normalized);

        return $normalized;
    }

    //log
    private function log(string $type, $data): void
    {
        $logDirectory = __DIR__ . '/storage';
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0770, true);
        }
        if (!is_writable($logDirectory)) {
            error_log('CissyTech CyberSource logging unavailable: storage directory is not writable.');
            return;
        }
        $logFile = $logDirectory . '/cybersource.log';

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

    /**
     * HMAC-SHA256 the signing string with the base64-decoded shared secret,
     * then base64-encode the result. (Swap this out for RSA/JWT later.)
     */
    private function sign(string $signingString): string
    {
        $decodedSecret = base64_decode($this->secretKey, true);
        if ($decodedSecret === false) {
            throw new InvalidArgumentException('secret_key must be a valid Base64-encoded shared secret');
        }

        $rawHmac = hash_hmac('sha256', $signingString, $decodedSecret, true);
        return base64_encode($rawHmac);
    }

    // -------------------------------------------------------------------------
    // Response envelope  (your standard shape)
    // -------------------------------------------------------------------------

    private function normalize(int $code, string $raw): array
    {
        $data = json_decode($raw, true);
        $ok = ($code >= 200 && $code < 300);
        $status = $data['status'] ?? null;
        $id = $data['id'] ?? null;

        // CyberSource success statuses worth treating as "good"
        $goodStatuses = [
            'AUTHORIZED',
            'PARTIAL_AUTHORIZED',
            'PENDING',
            'TRANSMITTED',
            'CREATED',
            'SENT',
            'COMPLETED',
            'VOIDED',
            'REVERSED',
            'PAID'
        ];

        $success = $ok && ($status === null || in_array($status, $goodStatuses, true));

        $message = $data['message']
            ?? $data['errorInformation']['message']
            ?? $status
            ?? ($raw !== '' ? $raw : null)
            ?? ($ok ? 'OK' : 'Request failed');

        $error = $ok ? null : ($data['reason'] ?? $data['errorInformation']['reason'] ?? $message);

        return $this->envelope($success, $code, $status, $id, $data, $raw, $error, $message);
    }

    private function envelope(
        bool $success,
        int $code,
        ?string $status,
        ?string $id,
        ?array $data,
        ?string $raw,
        ?string $error,
        ?string $message = null
    ): array {
        $this->last = [
            'success' => $success,
            'code' => $code,
            'status' => $status,
            'id' => $id,
            'message' => $message ?? $status,
            'error' => $error,
            'data' => $data,
            'raw' => $raw,
        ];
        return $this->last;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    public function lastResponse(): array
    {
        return $this->last;
    }
    public function getMode(): string
    {
        return $this->mode;
    }
    public function getEnvironment(): string
    {
        return $this->environment;
    }
}
