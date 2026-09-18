<?php
declare(strict_types=1);

namespace Pegasus;

use App\Config;

/** PegPay HTTP, credentials, and RSA signature handling. */
final class PegasusClient
{
    public function configured(): bool
    {
        foreach (['PEGASUS_API_URL', 'PEGASUS_VENDOR_CODE', 'PEGASUS_PASSWORD', 'PEGASUS_PRIVATE_KEY_PATH'] as $key) {
            if (trim((string) Config::get($key, '')) === '') return false;
        }
        return true;
    }

    public function requireConfiguration(): void
    {
        if (!$this->configured()) {
            throw new \LogicException('PegPay is not configured. Add its URL, vendor credentials, and private-key path to .env.');
        }
        if (!is_file($this->config()['private_key_path'])) {
            throw new \LogicException('PEGASUS_PRIVATE_KEY_PATH must point to the RSA private signing key file.');
        }
    }

    public function validateRecipient(string $account, string $network): array
    {
        $config = $this->config();
        $phoneNumber = $account . ',' . $network;
        return $this->request([
            'Method' => 'ValidatePhoneNumber',
            'PhoneNumber' => $phoneNumber,
            'VendorCode' => $config['vendor_code'],
            'Password' => $config['password'],
            'Signature' => $this->sign($phoneNumber . $config['vendor_code'] . $config['password']),
        ]);
    }

    public function postTransaction(array $payload): array
    {
        $config = $this->config();
        $dataToSign = ($payload['CustomerRef'] ?? '') . ($payload['CustomerName'] ?? '')
            . $payload['FromTelecom'] . $payload['ToTelecom'] . $payload['VendorTranId']
            . $config['vendor_code'] . $config['password'] . $payload['PaymentDate']
            . $payload['TranType'] . $payload['PaymentCode'] . $payload['TranAmount']
            . ($payload['FromAccount'] ?? '') . ($payload['ToAccount'] ?? '');
        $payload += [
            'VendorCode' => $config['vendor_code'],
            'Password' => $config['password'],
            'DigitalSignature' => $this->sign($dataToSign),
        ];
        if ($config['client_ip'] !== '') $payload['IP'] = $config['client_ip'];
        return $this->request($payload);
    }

    public function transactionStatus(string $vendorTransactionId): array
    {
        $config = $this->config();
        return $this->request([
            'Method' => 'GetTransactionDetails',
            'VendorCode' => $config['vendor_code'],
            'Password' => $config['password'],
            'VendorTranId' => $vendorTransactionId,
        ]);
    }

    private function request(array $payload): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL is required for PegPay.');
        $url = rtrim($this->config()['url'], '/') . '/';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false) throw new \RuntimeException('PegPay transport error: ' . $error);
        $response = json_decode((string) $raw, true);
        if (!is_array($response)) throw new \RuntimeException('PegPay returned an invalid JSON response.');
        if ($status >= 400) throw new \RuntimeException('PegPay returned HTTP ' . $status . '.');
        return $response;
    }

    private function sign(string $data): string
    {
        if (!preg_match('/^[\x20-\x7E]*$/', $data)) {
            throw new \InvalidArgumentException('PegPay signed fields must contain ASCII characters only.');
        }
        $config = $this->config();
        $keyMaterial = (string) file_get_contents($config['private_key_path']);
        if (str_contains($keyMaterial, 'BEGIN CERTIFICATE')) {
            throw new \RuntimeException('PegPay requires an RSA private signing key. A public certificate cannot sign data.');
        }
        $key = openssl_pkey_get_private($keyMaterial, $config['private_key_passphrase']);
        if ($key === false) throw new \RuntimeException('PegPay private key could not be loaded.');
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('PegPay digital signature could not be created.');
        }
        return base64_encode($signature);
    }

    private function config(): array
    {
        return [
            'url' => trim((string) Config::get('PEGASUS_API_URL', '')),
            'vendor_code' => trim((string) Config::get('PEGASUS_VENDOR_CODE', '')),
            'password' => (string) Config::get('PEGASUS_PASSWORD', ''),
            'private_key_path' => trim((string) Config::get('PEGASUS_PRIVATE_KEY_PATH', '')),
            'private_key_passphrase' => (string) Config::get('PEGASUS_PRIVATE_KEY_PASSPHRASE', ''),
            'client_ip' => trim((string) Config::get('PEGASUS_CLIENT_IP', '')),
        ];
    }
}
