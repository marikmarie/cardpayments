<?php
declare(strict_types=1);

namespace CissyTech\Pegasus\Sdk;

/**
 * Stateless PegPay Payments and Collections API client.
 *
 * The consuming system supplies credentials, chooses its own transaction ID,
 * and owns persistence, idempotency, retries, logs, and wallet changes.
 */
final class PegasusApiClient
{
    private string $apiUrl;
    private string $vendorCode;
    private string $password;
    private string $privateKey;
    private string $privateKeyPassphrase;
    private string $clientIp;
    private int $timeoutSeconds;

    /** @var null|callable(string, string, array<int, string>, string, int): array{status: int, body: string} */
    private $transport;

    /**
     * @param null|callable(string, string, array<int, string>, string, int): array{status: int, body: string} $transport
     */
    public function __construct(
        string $apiUrl,
        string $vendorCode,
        string $password,
        string $privateKey,
        string $privateKeyPassphrase = '',
        string $clientIp = '',
        int $timeoutSeconds = 30,
        ?callable $transport = null,
    ) {
        $this->apiUrl = rtrim(trim($apiUrl), '/') . '/';
        $this->vendorCode = trim($vendorCode);
        $this->password = $password;
        $this->privateKey = $privateKey;
        $this->privateKeyPassphrase = $privateKeyPassphrase;
        $this->clientIp = trim($clientIp);
        $this->timeoutSeconds = max(5, $timeoutSeconds);
        $this->transport = $transport;

        if (!filter_var($this->apiUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($this->apiUrl), 'https://')) {
            throw new \InvalidArgumentException('apiUrl must be an HTTPS PegPay API URL.');
        }
        foreach (['vendorCode' => $this->vendorCode, 'password' => $this->password, 'privateKey' => $this->privateKey] as $field => $value) {
            if (trim($value) === '') throw new \InvalidArgumentException("PegPay {$field} is required.");
        }
    }

    /** Verify whether an account is registered on its requested telecom or bank network. */
    public function validateRecipient(string $account, string $network): array
    {
        $account = $this->account($account);
        $network = $this->network($network);
        $phoneNumber = $account . ',' . $network;
        return $this->request([
            'Method' => 'ValidatePhoneNumber',
            'PhoneNumber' => $phoneNumber,
            'VendorCode' => $this->vendorCode,
            'Password' => $this->password,
            'Signature' => $this->sign($phoneNumber . $this->vendorCode . $this->password),
        ]);
    }

    /** Submit a mobile-money or bank collection/payout and return PegPay's response JSON. */
    public function transaction(array $parameters): array
    {
        $transaction = $this->transactionInput($parameters);
        return $this->postTransaction($this->transactionPayload($transaction));
    }

    /** Submit a fully prepared PegPay transaction payload. */
    public function postTransaction(array $payload): array
    {
        foreach (['FromTelecom', 'ToTelecom', 'VendorTranId', 'PaymentDate', 'TranType', 'PaymentCode', 'TranAmount'] as $field) {
            if (!isset($payload[$field]) || !is_scalar($payload[$field]) || trim((string) $payload[$field]) === '') {
                throw new \InvalidArgumentException("{$field} is required for a PegPay transaction.");
            }
        }
        $dataToSign = (string) ($payload['CustomerRef'] ?? '') . (string) ($payload['CustomerName'] ?? '')
            . $payload['FromTelecom'] . $payload['ToTelecom'] . $payload['VendorTranId']
            . $this->vendorCode . $this->password . $payload['PaymentDate']
            . $payload['TranType'] . $payload['PaymentCode'] . $payload['TranAmount']
            . (string) ($payload['FromAccount'] ?? '') . (string) ($payload['ToAccount'] ?? '');
        $payload['VendorCode'] = $this->vendorCode;
        $payload['Password'] = $this->password;
        $payload['DigitalSignature'] = $this->sign($dataToSign);
        if ($this->clientIp !== '') $payload['IP'] = $this->clientIp;
        return $this->request($payload);
    }

    /** Retrieve the latest PegPay status for a caller-managed transaction ID. */
    public function transactionStatus(string $vendorTransactionId): array
    {
        return $this->request([
            'Method' => 'GetTransactionDetails',
            'VendorCode' => $this->vendorCode,
            'Password' => $this->password,
            'VendorTranId' => $this->reference($vendorTransactionId),
        ]);
    }

    /** Retrieve the vendor account balance. */
    public function balance(): array
    {
        return $this->request([
            'Method' => 'GetAccountBalance',
            'VendorCode' => $this->vendorCode,
            'Password' => $this->password,
        ]);
    }

    private function request(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            $response = $this->transport
                ? ($this->transport)('POST', $this->apiUrl, ['Content-Type: application/json', 'Accept: application/json'], $body, $this->timeoutSeconds)
                : $this->curl($body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('PegPay connection failed: ' . $e->getMessage(), 0, $e);
        }

        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('PegPay returned an invalid JSON response (HTTP ' . $status . ').');
        if ($status >= 400) throw new \RuntimeException('PegPay returned HTTP ' . $status . '.');
        return $decoded;
    }

    /** @return array{status: int, body: string} */
    private function curl(string $body): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL is required for PegPay.');
        $curl = curl_init($this->apiUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
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

    /** @return array<string, string> */
    private function transactionInput(array $input): array
    {
        $type = strtoupper(trim((string) ($input['transaction_type'] ?? $input['transactionType'] ?? '')));
        if (!in_array($type, ['PULL', 'PUSH'], true)) throw new \InvalidArgumentException('transaction_type must be PULL or PUSH.');
        $id = $this->reference((string) ($input['vendor_transaction_id'] ?? $input['vendorTransactionId'] ?? ''));
        $fromAccount = trim((string) ($input['from_account'] ?? $input['fromAccount'] ?? ''));
        $toAccount = trim((string) ($input['to_account'] ?? $input['toAccount'] ?? ''));
        $fromNetwork = trim((string) ($input['from_network'] ?? $input['fromNetwork'] ?? ''));
        $toNetwork = trim((string) ($input['to_network'] ?? $input['toNetwork'] ?? ''));
        if ($type === 'PULL') {
            $fromAccount = $this->account($fromAccount);
            $fromNetwork = $this->network($fromNetwork);
            $toAccount = '';
            $toNetwork = $fromNetwork;
        } else {
            $toAccount = $this->account($toAccount);
            $toNetwork = $this->network($toNetwork);
            $fromAccount = $fromAccount === '' ? '' : $this->account($fromAccount);
            $fromNetwork = $fromNetwork === '' ? $toNetwork : $this->network($fromNetwork);
        }

        $amount = trim((string) ($input['amount'] ?? ''));
        if (!preg_match('/^[1-9]\d*$/', $amount)) throw new \InvalidArgumentException('amount must be a positive whole UGX value.');
        $customerName = $this->text($input['customer_name'] ?? $input['customerName'] ?? '', 100, 'customer_name');
        $customerReference = $this->text($input['customer_reference'] ?? $input['customerReference'] ?? '', 100, 'customer_reference');
        $narration = $this->text($input['narration'] ?? '', 200, 'narration');
        $whitelist = strtoupper(trim((string) ($input['whitelist'] ?? '')));
        if ($whitelist !== '' && !in_array($whitelist, ['FROMACCOUNT', 'TOACCOUNT', 'BOTH'], true)) {
            throw new \InvalidArgumentException('whitelist must be FROMACCOUNT, TOACCOUNT, or BOTH.');
        }

        return compact('id', 'type', 'amount', 'fromAccount', 'toAccount', 'fromNetwork', 'toNetwork', 'customerName', 'customerReference', 'narration', 'whitelist');
    }

    /** @param array<string, string> $transaction */
    private function transactionPayload(array $transaction): array
    {
        $payload = [
            'Method' => 'PostTransaction',
            'SessionId' => $transaction['id'],
            'Narration' => $transaction['narration'],
            'AddendumData' => $transaction['whitelist'] === '' ? '' : 'whitelist:' . $transaction['whitelist'],
            'FromTelecom' => $transaction['fromNetwork'],
            'ToTelecom' => $transaction['toNetwork'],
            'PaymentCode' => $transaction['fromNetwork'] === $transaction['toNetwork'] ? '1' : '2',
            'PaymentDate' => gmdate('Y-m-d'),
            'Telecom' => $transaction['type'] === 'PULL' ? $transaction['fromNetwork'] : $transaction['toNetwork'],
            'CustomerRef' => $transaction['customerReference'],
            'CustomerName' => $transaction['customerName'],
            'TranAmount' => $transaction['amount'],
            'TranCharge' => '0',
            'VendorTranId' => $transaction['id'],
            'FromAccount' => $transaction['fromAccount'],
            'TranType' => $transaction['type'],
        ];
        if ($transaction['type'] === 'PUSH') $payload['ToAccount'] = $transaction['toAccount'];
        return $payload;
    }

    /** Build the documented RSA-SHA1 PegPay signature. */
    private function sign(string $data): string
    {
        if (!preg_match('/^[\x20-\x7E]*$/', $data)) throw new \InvalidArgumentException('PegPay signed fields must contain ASCII characters only.');
        if (str_contains($this->privateKey, 'BEGIN CERTIFICATE')) {
            throw new \RuntimeException('PegPay requires an RSA private signing key, not a certificate.');
        }
        $key = openssl_pkey_get_private($this->privateKey, $this->privateKeyPassphrase);
        if ($key === false) throw new \RuntimeException('PegPay private key could not be loaded.');
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA1)) throw new \RuntimeException('PegPay digital signature could not be created.');
        return base64_encode($signature);
    }

    private function reference(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^PMT[A-Z0-9]{7}$/', $value)) {
            throw new \InvalidArgumentException('vendor_transaction_id must start with PMT and contain exactly 10 letters or numbers.');
        }
        return $value;
    }

    private function account(string $value): string
    {
        $value = preg_replace('/\s+/', '', $value);
        if (!preg_match('/^\d{7,30}$/', $value)) throw new \InvalidArgumentException('account must contain 7-30 digits.');
        return $value;
    }

    private function network(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $value)) throw new \InvalidArgumentException('network must be a valid PegPay bank or telecom code.');
        return $value;
    }

    private function text(mixed $value, int $length, string $field): string
    {
        $value = trim((string) $value);
        if (strlen($value) > $length || !preg_match('/^[\x20-\x7E]*$/', $value)) {
            throw new \InvalidArgumentException("{$field} must contain at most {$length} ASCII characters.");
        }
        return $value;
    }
}
