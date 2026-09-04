<?php
declare(strict_types=1);

namespace App\Models;

use App\Store;

/** Card payment-link repository. */
final class PaymentLink
{
    public function __construct(private Store $store) {}

    public function all(): array
    {
        $rows = $this->store->read('payment_links');
        usort($rows, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    public function find(string $id): ?array
    {
        foreach ($this->store->read('payment_links') as $row) {
            if ($row['id'] === $id) return $row;
        }
        return null;
    }

    public function findByInvoiceNumber(string $invoiceNumber): ?array
    {
        foreach ($this->store->read('payment_links') as $row) {
            if (($row['invoice_number'] ?? null) === $invoiceNumber) return $row;
        }
        return null;
    }

    public function create(array $values): array
    {
        $row = $values + [
            'id' => bin2hex(random_bytes(12)),
            'status' => 'CREATED',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        $this->store->transaction(function (array &$data) use ($row): void {
            $data['payment_links'][] = $row;
        });
        return $row;
    }

    public function update(string $id, array $changes): ?array
    {
        return $this->store->transaction(function (array &$data) use ($id, $changes): ?array {
            if (empty($data['payment_links'])) return null;
            foreach ($data['payment_links'] as &$row) {
                if ($row['id'] === $id) {
                    $row = array_merge($row, $changes, ['updated_at' => gmdate('c')]);
                    return $row;
                }
            }
            return null;
        });
    }

    public function updateByProviderInvoiceId(string $providerInvoiceId, array $changes): ?array
    {
        return $this->store->transaction(function (array &$data) use ($providerInvoiceId, $changes): ?array {
            if (empty($data['payment_links'])) return null;
            foreach ($data['payment_links'] as &$row) {
                if (($row['provider_invoice_id'] ?? null) === $providerInvoiceId) {
                    $row = array_merge($row, $changes, ['updated_at' => gmdate('c')]);
                    return $row;
                }
            }
            return null;
        });
    }
}
