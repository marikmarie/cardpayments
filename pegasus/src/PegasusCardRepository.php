<?php
declare(strict_types=1);

namespace Pegasus;

use App\Store;

/** Dedicated MySQL store for hosted PegPay card collections. */
final class PegasusCardRepository
{
    private bool $available = true;

    public function __construct(private Store $store) {}

    /** Returns false when MySQL or the new table is unavailable, allowing the JSON fallback. */
    public function save(array $record): bool
    {
        $pdo = $this->database();
        if (!$pdo) return false;

        try {
            $query = $pdo->prepare(
                'INSERT INTO tbl_pegasus_cards (
                    vendor_transaction_id, amount, currency, description, customer_name, customer_email,
                    return_url, source_ip, status, gateway_status, gateway_reason, pegpay_transaction_id,
                    response_signature_valid, status_query_response, status_query_http_status,
                    status_query_requested_at, status_queried_at, returned_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    amount = VALUES(amount), currency = VALUES(currency), description = VALUES(description),
                    customer_name = VALUES(customer_name), customer_email = VALUES(customer_email),
                    return_url = VALUES(return_url), source_ip = VALUES(source_ip), status = VALUES(status),
                    gateway_status = VALUES(gateway_status), gateway_reason = VALUES(gateway_reason),
                    pegpay_transaction_id = VALUES(pegpay_transaction_id),
                    response_signature_valid = VALUES(response_signature_valid),
                    status_query_response = VALUES(status_query_response),
                    status_query_http_status = VALUES(status_query_http_status),
                    status_query_requested_at = VALUES(status_query_requested_at),
                    status_queried_at = VALUES(status_queried_at), returned_at = VALUES(returned_at),
                    updated_at = VALUES(updated_at)'
            );
            $query->execute([
                $record['id'], $record['amount'], $record['currency'], $record['description'],
                $record['customer_name'] ?: null, $record['customer_email'] ?: null,
                $record['return_url'], $record['source_ip'] ?? null, $record['status'] ?? 'PENDING',
                $record['gateway_status'] ?? null, $record['gateway_reason'] ?? null,
                $record['pegpay_transaction_id'] ?? null,
                array_key_exists('response_signature_valid', $record) ? (int) (bool) $record['response_signature_valid'] : null,
                $this->json($record['status_query_response'] ?? null), $record['status_query_http_status'] ?? null,
                $this->databaseTime($record['status_query_requested_at'] ?? null),
                $this->databaseTime($record['status_queried_at'] ?? null),
                $this->databaseTime($record['returned_at'] ?? null),
                $this->databaseTime($record['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s.u'),
                $this->databaseTime($record['updated_at'] ?? null) ?? gmdate('Y-m-d H:i:s.u'),
            ]);
            return true;
        } catch (\PDOException) {
            $this->available = false;
            error_log('PegPay card table is unavailable. Import pegasus/database/schema.mysql.sql.');
            return false;
        }
    }

    public function find(string $id): ?array
    {
        $pdo = $this->database();
        if (!$pdo) return null;

        try {
            $query = $pdo->prepare('SELECT * FROM tbl_pegasus_cards WHERE vendor_transaction_id = ? LIMIT 1');
            $query->execute([$id]);
            $row = $query->fetch();
            return is_array($row) ? $this->record($row) : null;
        } catch (\PDOException) {
            $this->available = false;
            error_log('PegPay card table is unavailable. Import pegasus/database/schema.mysql.sql.');
            return null;
        }
    }

    /** @return list<array>|null Null means the dedicated table is unavailable. */
    public function recent(int $limit = 8): ?array
    {
        $pdo = $this->database();
        if (!$pdo) return null;

        try {
            $query = $pdo->prepare('SELECT * FROM tbl_pegasus_cards ORDER BY created_at DESC LIMIT ?');
            $query->bindValue(1, $limit, \PDO::PARAM_INT);
            $query->execute();
            return array_map(fn(array $row): array => $this->record($row), $query->fetchAll());
        } catch (\PDOException) {
            $this->available = false;
            error_log('PegPay card table is unavailable. Import pegasus/database/schema.mysql.sql.');
            return null;
        }
    }

    private function database(): ?\PDO
    {
        return $this->available ? $this->store->pdo() : null;
    }

    private function record(array $row): array
    {
        return array_filter([
            'id' => $row['vendor_transaction_id'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'description' => $row['description'],
            'customer_name' => $row['customer_name'] ?? '',
            'customer_email' => $row['customer_email'] ?? '',
            'return_url' => $row['return_url'],
            'source_ip' => $row['source_ip'],
            'status' => $row['status'],
            'gateway_status' => $row['gateway_status'],
            'gateway_reason' => $row['gateway_reason'],
            'pegpay_transaction_id' => $row['pegpay_transaction_id'],
            'response_signature_valid' => $row['response_signature_valid'] === null ? null : (bool) $row['response_signature_valid'],
            'status_query_response' => $this->decode($row['status_query_response']),
            'status_query_http_status' => $row['status_query_http_status'] === null ? null : (int) $row['status_query_http_status'],
            'status_query_requested_at' => $this->isoTime($row['status_query_requested_at']),
            'status_queried_at' => $this->isoTime($row['status_queried_at']),
            'returned_at' => $this->isoTime($row['returned_at']),
            'created_at' => $this->isoTime($row['created_at']),
            'updated_at' => $this->isoTime($row['updated_at']),
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) return null;
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? null : $json;
    }

    private function decode(?string $json): mixed
    {
        return $json === null ? null : (json_decode($json, true) ?? $json);
    }

    private function databaseTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Exception) {
            return null;
        }
    }

    private function isoTime(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
