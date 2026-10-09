<?php
declare(strict_types=1);

namespace App\Models;

use App\PaymentReference;
use App\Store;

/** Integration credential repository; the token remains the only secret. */
final class ApiKey
{
    public function __construct(private Store $store) {}

    public function all(): array
    {
        $keys = $this->store->read('api_keys');
        usort($keys, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));
        return array_map(function (array $key): array {
            unset($key['token_hash']);
            return $key;
        }, $keys);
    }

    /** Find an integration by its non-secret dashboard ID. */
    public function find(string $id): ?array
    {
        foreach ($this->store->read('api_keys') as $key) {
            if (hash_equals((string) ($key['id'] ?? ''), $id)) {
                unset($key['token_hash']);
                return $key;
            }
        }
        return null;
    }

    /** Create a dashboard-visible PMT ID and a separate high-entropy API token. */
    public function create(string $name): array
    {
        $token = 'plk_test_' . bin2hex(random_bytes(20));
        $row = $this->store->transaction(function (array &$data) use ($name, $token): array {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $id = PaymentReference::generate();
                foreach ($data['api_keys'] ?? [] as $key) {
                    if (($key['id'] ?? null) === $id) {
                        continue 2;
                    }
                }
                $row = [
                    'id' => $id,
                    'name' => $name,
                    'token_hash' => hash('sha256', $token),
                    'last_used_at' => null,
                    'created_at' => gmdate('c'),
                ];
                $data['api_keys'][] = $row;
                return $row;
            }

            throw new \RuntimeException('Could not allocate a unique API key ID. Please try again.');
        });
        return $row + ['token' => $token];
    }

    /**
     * Return the non-secret key record for an authenticated integration.
     * EFRIS uses the key ID to resolve its tenant on the server; callers never
     * send a tenant, TIN, device number, or cryptographic material.
     */
    public function authenticate(?string $token): ?array
    {
        if (!$token) return null;
        $hash = hash('sha256', $token);
        return $this->store->transaction(function (array &$data) use ($hash): ?array {
            if (empty($data['api_keys'])) return null;
            foreach ($data['api_keys'] as &$row) {
                if (hash_equals($row['token_hash'], $hash)) {
                    $row['last_used_at'] = gmdate('c');
                    $authenticated = $row;
                    unset($authenticated['token_hash']);
                    return $authenticated;
                }
            }
            return null;
        });
    }

    public function revoke(string $id): bool
    {
        return $this->store->transaction(function (array &$data) use ($id): bool {
            $keys = $data['api_keys'] ?? [];
            $remaining = array_values(array_filter($keys, fn(array $key) => $key['id'] !== $id));
            if (count($remaining) === count($keys)) return false;
            $data['api_keys'] = $remaining;
            return true;
        });
    }
}
