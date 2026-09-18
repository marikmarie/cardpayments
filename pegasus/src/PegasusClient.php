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

    public function balance(): array
    {
        $config = $this->config();
        return $this->request([
            'Method' => 'GetAccountBalance',
            'VendorCode' => $config['vendor_code'],
            'Password' => $config['password'],
        ]);
    }

    /** @return array{path: string, writable: bool, entries: array} */
    public function logDetails(): array
    {
        $directory = $this->logDirectory();
        if (is_dir($directory) && !is_writable($directory)) @chmod($directory, 0775);
        $path = $directory . '/pegasus.log';
        $entries = [];
        if (is_readable($path)) {
            $size = filesize($path) ?: 0;
            $contents = (string) file_get_contents($path, false, null, max(0, $size - 65536));
            foreach (array_reverse(array_filter(explode(PHP_EOL, $contents))) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) $entries[] = $entry;
                if (count($entries) === 12) break;
            }
        }
        return ['path' => 'storage/pegasus.log', 'writable' => is_dir($directory) && is_writable($directory), 'entries' => $entries];
    }

    private function request(array $payload): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL is required for PegPay.');
        $url = rtrim($this->config()['url'], '/') . '/';
        $operation = (string) ($payload['Method'] ?? 'unknown');
        $this->logRequest($operation, $url, $payload);

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

        if ($raw === false) {
            $this->logResponse($operation, $url, $status, null, $error);
            throw new \RuntimeException('PegPay transport error: ' . $error);
        }
        $response = json_decode((string) $raw, true);
        if (!is_array($response)) {
            $this->logResponse($operation, $url, $status, $raw, 'PegPay returned invalid JSON.');
            throw new \RuntimeException('PegPay returned an invalid JSON response.');
        }
        $this->logResponse($operation, $url, $status, $response);
        if ($status >= 400) throw new \RuntimeException('PegPay returned HTTP ' . $status . '.');
        return $response;
    }

    /** Store the outgoing request without exposing provider credentials or signatures. */
    private function logRequest(string $operation, string $url, array $payload): void
    {
        $this->writeLog('REQUEST', [
            'operation' => $operation,
            'method' => 'POST',
            'url' => $url,
            'body' => $this->redact($payload),
        ]);
    }

    private function logResponse(string $operation, string $url, int $status, mixed $response, ?string $error = null): void
    {
        $this->writeLog('RESPONSE', array_filter([
            'operation' => $operation,
            'url' => $url,
            'http_status' => $status,
            'response' => $this->redact($response),
            'error' => $error,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function writeLog(string $type, array $data): void
    {
        $directory = $this->logDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('PegPay log directory could not be created.');
            return;
        }
        if (!is_writable($directory)) @chmod($directory, 0775);
        if (!is_writable($directory)) {
            error_log('PegPay log directory is not writable.');
            return;
        }

        $entry = ['time' => gmdate('c'), 'type' => $type, 'data' => $data];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false || @file_put_contents($directory . '/pegasus.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('PegPay log could not be written.');
        }
    }

    private function logDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage';
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) return $value;

        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['password', 'signature', 'digitalsignature', 'authorization'], true)) {
                $value[$key] = '[redacted]';
                continue;
            }
            $value[$key] = $this->redact($item);
        }
        return $value;
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
            'private_key_path' => $this->privateKeyPath(),
            'private_key_passphrase' => (string) Config::get('PEGASUS_PRIVATE_KEY_PASSPHRASE', ''),
            'client_ip' => trim((string) Config::get('PEGASUS_CLIENT_IP', '')),
        ];
    }

    /** Resolve a relative key path from the project root, not Apache's working folder. */
    private function privateKeyPath(): string
    {
        $path = trim((string) Config::get('PEGASUS_PRIVATE_KEY_PATH', ''));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, './\\');
    }
}
