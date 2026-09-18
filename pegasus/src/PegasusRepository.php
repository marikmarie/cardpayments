<?php
declare(strict_types=1);

namespace Pegasus;

use App\Store;

/** MySQL mirror for Pegasus records; local JSON remains available for development. */
final class PegasusRepository
{
    private bool $available = true;

    public function __construct(private Store $store) {}

    public function saveTransaction(array $record): void
    {
        $pdo = $this->database();
        if (!$pdo) return;

        try {
            $query = $pdo->prepare(
                'INSERT INTO tbl_pegasus_transactions (
                    vendor_transaction_id, transaction_type, amount, currency, from_account, to_account,
                    from_network, to_network, payment_code, customer_reference, customer_name, narration,
                    status, provider_status_code, provider_status_description, pegpay_id, telecom_id,
                    request_payload, provider_response
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    transaction_type = VALUES(transaction_type), amount = VALUES(amount), currency = VALUES(currency),
                    from_account = VALUES(from_account), to_account = VALUES(to_account),
                    from_network = VALUES(from_network), to_network = VALUES(to_network), payment_code = VALUES(payment_code),
                    customer_reference = VALUES(customer_reference), customer_name = VALUES(customer_name), narration = VALUES(narration),
                    status = VALUES(status), provider_status_code = VALUES(provider_status_code),
                    provider_status_description = VALUES(provider_status_description), pegpay_id = VALUES(pegpay_id),
                    telecom_id = VALUES(telecom_id), request_payload = VALUES(request_payload), provider_response = VALUES(provider_response)'
            );
            $query->execute([
                $record['id'], $record['transaction_type'], $record['amount'], 'UGX',
                $record['from_account'] ?? null, $record['to_account'] ?? null,
                $record['from_network'] ?? null, $record['to_network'] ?? null, $record['payment_code'] ?? null,
                $record['customer_reference'] ?? null, $record['customer_name'] ?? null, $record['narration'] ?? null,
                $record['status'] ?? 'PENDING', $record['status_code'] ?? null, $record['status_description'] ?? null,
                $record['pegpay_id'] ?? null, $record['telecom_id'] ?? null,
                $this->json($record['request_payload'] ?? null), $this->json($record['provider_response'] ?? null),
            ]);
        } catch (\PDOException $e) {
            $this->disable('PegPay transaction table is unavailable. Import pegasus/database/schema.mysql.sql.');
        }
    }

    /** @return list<array> */
    public function recentLogs(int $limit = 12): array
    {
        $pdo = $this->database();
        if (!$pdo) return [];

        try {
            $query = $pdo->prepare('SELECT * FROM tbl_pegasus_api_logs ORDER BY id DESC LIMIT ?');
            $query->bindValue(1, $limit, \PDO::PARAM_INT);
            $query->execute();
            return array_map(fn(array $row): array => [
                'time' => $row['created_at'],
                'type' => $row['log_type'],
                'data' => array_filter([
                    'operation' => $row['request_type'],
                    'vendor_transaction_id' => $row['vendor_transaction_id'],
                    'method' => $row['http_method'],
                    'url' => $row['endpoint'],
                    'http_status' => $row['http_status'],
                    'provider_status_code' => $row['provider_status_code'],
                    'provider_status_description' => $row['provider_status_description'],
                    'payload' => $this->decode($row['payload']),
                    'error' => $row['error_message'],
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
            ], $query->fetchAll());
        } catch (\PDOException $e) {
            $this->disable('PegPay activity table is unavailable. Import pegasus/database/schema.mysql.sql.');
            return [];
        }
    }

    public function recordLog(array $entry): void
    {
        $pdo = $this->database();
        if (!$pdo) return;

        $data = $entry['data'] ?? [];
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        try {
            $query = $pdo->prepare(
                'INSERT INTO tbl_pegasus_api_logs (
                    vendor_transaction_id, log_type, request_type, http_method, endpoint, http_status,
                    provider_status_code, provider_status_description, payload, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $query->execute([
                $data['vendor_transaction_id'] ?? null,
                $entry['type'] ?? 'UNKNOWN',
                $data['operation'] ?? 'unknown',
                $data['method'] ?? null,
                $data['url'] ?? null,
                $data['http_status'] ?? null,
                $response['StatusCode'] ?? $response['Status'] ?? null,
                $response['StatusDescription'] ?? $response['StatusDesc'] ?? null,
                $this->json($data['body'] ?? $data['response'] ?? null),
                $data['error'] ?? null,
            ]);
        } catch (\PDOException $e) {
            $this->disable('PegPay activity table is unavailable. Import pegasus/database/schema.mysql.sql.');
        }
    }

    private function database(): ?\PDO
    {
        return $this->available ? $this->store->pdo() : null;
    }

    private function disable(string $message): void
    {
        $this->available = false;
        error_log($message);
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
}
