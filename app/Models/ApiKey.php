<?php
declare(strict_types=1);

namespace App\Models;

use App\Store;

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

    public function create(string $name): array
    {
        $token = 'plk_test_' . bin2hex(random_bytes(20));
        $row = [
            'id' => bin2hex(random_bytes(12)),
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'last_used_at' => null,
            'created_at' => gmdate('c'),
        ];
        $this->store->transaction(function (array &$data) use ($row): void {
            $data['api_keys'][] = $row;
        });
        return $row + ['token' => $token];
    }

    public function verify(?string $token): bool
    {
        return $this->authenticate($token) !== null;
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
