<?php
declare(strict_types=1);

namespace App\Models;

use App\Store;

final class Transaction
{
    public function __construct(private Store $store) {}

    public function all(): array
    {
        $rows = $this->store->read('transactions');
        usort($rows, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    public function create(array $values): array
    {
        $row = $values + ['id' => bin2hex(random_bytes(12)), 'created_at' => gmdate('c')];
        $this->store->transaction(function (array &$data) use ($row): void {
            $data['transactions'][] = $row;
        });
        return $row;
    }
}
