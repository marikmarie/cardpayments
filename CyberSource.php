<?php

class CyberSource
{
    private string $merchantId;
    private string $keyId;
    private string $sharedSecret;
    private string $host;
    private string $logFile;


    //constants
    const SANDBOX_HOST = 'apitest.cybersource.com';
    const PRODUCTION_HOST = 'api.cybersource.com';

    const CURRENCY = 'UGX';


    public function __construct(
        string $merchantId,
        string $keyId,
        string $sharedSecret,
        bool $sandbox = true,
        string $logFile = __DIR__ . '/cybersource.log'
    ) {
        $this->merchantId = $merchantId;
        $this->keyId = $keyId;
        $this->sharedSecret = $sharedSecret;
        $this->host = $sandbox ? self::SANDBOX_HOST : self::PRODUCTION_HOST;
        $this->logFile = $logFile;

        if ($this->sharedSecret === '') {
            throw new \RuntimeException('Shared secret must be provided for shared-secret authentication.');
        }
    }

    //Public API Methods
    public function createPayment(array $payload): array
    {
        return $this->post('/pts/v2/payments', $payload);
    }


    public function capturePayment(string $paymentId, int $amount): array
    {
        $payload = [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $amount,
                    'currency' => self::CURRENCY,
                ],
            ],
        ];

        return $this->post("/pts/v2/payments/{$paymentId}/captures", $payload);
    }

    //Void / reverse a payment before settlement.
    public function voidPayment(string $paymentId): array
    {
        return $this->post("/pts/v2/payments/{$paymentId}/voids", []);
    }

    //   Refund a captured (settled) payment.
    public function refundPayment(string $captureId, int $amount): array
    {
        $payload = [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $amount,
                    'currency' => self::CURRENCY,
                ],
            ],
        ];

        return $this->post("/pts/v2/captures/{$captureId}/refunds", $payload);
    }

  
    //  Retrieve details of an existing payment.
  
    public function getPayment(string $paymentId): array
    {
        return $this->get("/pts/v2/payments/{$paymentId}");
    }

    
    // payload
    public static function buildPaymentPayload(
        $amount,
        $cardNumber,
        $expiryMonth,
        $expiryYear,
        $cvv = '',
        string $firstName = 'Cissy',
        string $lastName = 'Nakato',
        string $email = 'cissy@example.co.ug',
        string $phone = '256772000001',
        string $reference = '',
        bool $captureNow = false,
        string $currency = 'UGX',
        array $billTo = []
    ): array {
        $card = [
            'number' => str_replace(' ', '', $cardNumber),
            'expirationMonth' => $expiryMonth,
            'expirationYear' => $expiryYear,
        ];

        if ($cvv !== '') {
            $card['securityCode'] = $cvv;
        }

        $defaultBillTo = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'address1' => 'Plot 1 Kampala Road',
            'locality' => 'Kampala',
            'administrativeArea' => 'Central',
            'postalCode' => '00000',
            'country' => 'UG',
            'email' => $email,
            'phoneNumber' => $phone,
        ];

        $billTo = array_merge($defaultBillTo, $billTo);

        $payload = [
            'clientReferenceInformation' => [
                'code' => $reference ?: 'UGX-AUTH-' . time(),
            ],
            'paymentInformation' => [
                'card' => $card,
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $amount,
                    'currency' => $currency,
                ],
                'billTo' => $billTo,
            ],
        ];

        if ($captureNow) {
            $payload['processingInformation'] = ['capture' => true];
        }

        return $payload;
    }


    //Http methods
        private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path, []);
    }

    //core request method
    private function request(string $method, string $path, array $body): array
    {
        $method = strtoupper($method);
        $jsonBody = '';
        $digest = '';
        $contentType = '';

        if ($method === 'POST' && !empty($body)) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($jsonBody === false) {
                throw new \RuntimeException('Failed to encode request body to JSON: ' . json_last_error_msg());
            }

            $digest = 'SHA-256=' . base64_encode(hash('sha256', $jsonBody, true));
            $contentType = 'application/json';
        }

        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $headers = $this->buildSignedHeaders($method, $path, $date, $digest, $contentType, $jsonBody);

        $this->log('REQUEST', [
            'method' => $method,
            'url' => "https://{$this->host}{$path}",
            'currency' => self::CURRENCY,
            'body' => json_decode($jsonBody, true)
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$this->host}{$path}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log('CURL_ERROR', ['error' => $curlError]);
            throw new \RuntimeException("cURL error: {$curlError}");
        }

        $decoded = json_decode($response, true) ?? ['raw' => $response];


        $this->log('RESPONSE', [
            'httpStatus' => $httpStatus,
            'response' => $decoded,
        ]);

        return [
            'httpStatus' => $httpStatus,
            'data' => $decoded,
        ];
    }


    //Create signature
    private function buildSignedHeaders(
        string $method,
        string $path,
        string $date,
        string $digest,
        string $contentType,
        string $jsonBody = ''
    ): array {
        $headersToSign = ($method === 'POST')
            ? ['host', 'date', 'request-target', 'digest', 'v-c-merchant-id']
            : ['host', 'date', 'request-target', 'v-c-merchant-id'];

        $signingParts = [];
        foreach ($headersToSign as $h) {
            switch ($h) {
                case 'host':
                    $signingParts[] = "host: {$this->host}";
                    break;
                case 'date':
                    $signingParts[] = "date: {$date}";
                    break;
                case 'request-target':
                    $signingParts[] = "(request-target): " . strtolower($method) . " {$path}";
                    break;
                case 'digest':
                    $signingParts[] = "digest: {$digest}";
                    break;
                case 'v-c-merchant-id':
                    $signingParts[] = "v-c-merchant-id: {$this->merchantId}";
                    break;
            }
        }

        $signingString = implode("\n", $signingParts);

        // Strip out any accidental spaces or line breaks before decoding
        $cleanSecret = preg_replace('/\s+/', '', $this->sharedSecret);
        $secret = base64_decode($cleanSecret, true);

        if ($secret === false) {
            throw new \RuntimeException('Invalid shared secret: must be base64 encoded.');
        }
        $secret = base64_decode($this->sharedSecret, true);
        if ($secret === false) {
            throw new \RuntimeException('Invalid shared secret: must be base64 encoded.');
        }

        $rawSignature = hash_hmac('sha256', $signingString, $secret, true);
        $signatureB64 = base64_encode($rawSignature);
        $algorithm = 'hmac-sha256';

        $headersAttr = implode(' ', $headersToSign);

        $authHeader = sprintf(
            'Signature keyid="%s", algorithm="%s", headers="%s", signature="%s"',
            $this->keyId,
            $algorithm,
            $headersAttr,
            $signatureB64
        );

        $headers = [
            "host: {$this->host}",
            "date: {$date}",
            "signature: {$authHeader}",
            "v-c-merchant-id: {$this->merchantId}",
        ];

        if ($method === 'POST') {
            $headers[] = "digest: {$digest}";
            $headers[] = "content-type: {$contentType}";
            $headers[] = "content-length: " . strlen($jsonBody);
        }

        return $headers;
    }


    //logging
       private function log(string $event, array $context = []): void
    {
        $entry = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            $event,
            json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if (@file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log(sprintf('CyberSource log failed: %s', $this->logFile));
        }
    }
}