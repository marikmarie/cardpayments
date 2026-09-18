<?php
declare(strict_types=1);

namespace Pegasus;

use App\Store;

/** PegPay transaction validation and local status records. */
final class PegasusService
{
    private PegasusClient $client;

    public function __construct(private Store $store)
    {
        $this->client = new PegasusClient();
    }

    public function validateRecipient(array $input): array
    {
        $this->client->requireConfiguration();
        $account = $this->account($input['account'] ?? '');
        $network = $this->network($input['network'] ?? '');
        $response = $this->client->validateRecipient($account, $network);
        return [
            'account' => $account,
            'network' => $response['Network'] ?? $network,
            'name' => $response['Name'] ?? null,
            'registered' => $response['IsRegistered'] ?? null,
            'status_code' => (string) ($response['Status'] ?? $response['StatusCode'] ?? ''),
            'status_description' => $response['StatusDescription'] ?? $response['StatusDesc'] ?? null,
        ];
    }

    /** @return array{record: array, replayed: bool} */
    public function createTransaction(array $input): array
    {
        $this->client->requireConfiguration();
        $transaction = $this->transactionInput($input);
        $reserved = $this->reserve($transaction);
        // PegPay Status Code 22 is explicitly retried with the same VendorTranId.
        if ($reserved['replayed'] && ($reserved['record']['status_code'] ?? '') !== '22') return $reserved;

        try {
            return ['record' => $this->saveResponse($transaction['id'], $this->client->postTransaction($this->postPayload($transaction))), 'replayed' => false];
        } catch (\Throwable $e) {
            $this->update($transaction['id'], [
                'status' => 'UNKNOWN',
                'status_description' => 'PegPay did not return a usable response. Check status before retrying.',
                'updated_at' => gmdate('c'),
            ]);
            throw $e;
        }
    }

    public function find(string $id): ?array
    {
        return $this->store->transaction(fn(array $state) => $state['pegasus_transactions'][$id] ?? null, false);
    }

    public function refresh(string $id): ?array
    {
        $this->client->requireConfiguration();
        if (!$this->find($id)) return null;
        return $this->saveResponse($id, $this->client->transactionStatus($id));
    }

    public function resource(array $record): array
    {
        return [
            'vendor_transaction_id' => $record['id'],
            'transaction_type' => $record['transaction_type'],
            'amount' => $record['amount'],
            'currency' => 'UGX',
            'status' => $record['status'],
            'status_code' => $record['status_code'] ?? null,
            'status_description' => $record['status_description'] ?? null,
            'pegpay_id' => $record['pegpay_id'] ?? null,
            'telecom_id' => $record['telecom_id'] ?? null,
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'] ?? null,
        ];
    }

    private function transactionInput(array $input): array
    {
        $type = strtoupper(trim((string) ($input['transaction_type'] ?? '')));
        if (!in_array($type, ['PULL', 'PUSH'], true)) {
            throw new \InvalidArgumentException('transaction_type must be PULL (collection) or PUSH (payout).');
        }
        $id = trim((string) ($input['vendor_transaction_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
            throw new \InvalidArgumentException('vendor_transaction_id must be a unique 1-60 character reference.');
        }
        $fromAccount = trim((string) ($input['from_account'] ?? ''));
        $toAccount = trim((string) ($input['to_account'] ?? ''));
        $fromNetwork = trim((string) ($input['from_network'] ?? ''));
        $toNetwork = trim((string) ($input['to_network'] ?? ''));
        if ($type === 'PULL') {
            $fromAccount = $this->account($fromAccount);
            $fromNetwork = $this->network($fromNetwork);
            // PegPay documents FromAccount for PULL transactions; no destination account is sent.
            $toAccount = '';
            $toNetwork = $fromNetwork;
        } else {
            $toAccount = $this->account($toAccount);
            $toNetwork = $this->network($toNetwork);
            if ($fromAccount !== '') $fromAccount = $this->account($fromAccount);
            $fromNetwork = $fromNetwork === '' ? $toNetwork : $this->network($fromNetwork);
        }
        $amount = trim((string) ($input['amount'] ?? ''));
        if (!preg_match('/^[1-9]\d*$/', $amount)) {
            throw new \InvalidArgumentException('amount must be a positive whole UGX value.');
        }
        $network = $type === 'PULL' ? $fromNetwork : $toNetwork;
        $minimum = in_array($network, ['MTN', 'AIRTEL'], true) ? 500 : 5000;
        if ((float) $amount < $minimum) throw new \InvalidArgumentException("PegPay minimum for {$network} is {$minimum} UGX.");

        $customerName = $this->text($input['customer_name'] ?? '', 100, 'customer_name');
        $customerRef = $this->text($input['customer_reference'] ?? '', 100, 'customer_reference');
        $narration = $this->text($input['narration'] ?? '', 200, 'narration');
        $whitelist = strtoupper(trim((string) ($input['whitelist'] ?? '')));
        if ($whitelist !== '' && !in_array($whitelist, ['FROMACCOUNT', 'TOACCOUNT', 'BOTH'], true)) {
            throw new \InvalidArgumentException('whitelist must be FROMACCOUNT, TOACCOUNT, or BOTH.');
        }
        return compact('id', 'type', 'amount', 'fromAccount', 'toAccount', 'fromNetwork', 'toNetwork', 'customerName', 'customerRef', 'narration', 'whitelist');
    }

    private function postPayload(array $transaction): array
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
            'CustomerRef' => $transaction['customerRef'],
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

    private function reserve(array $transaction): array
    {
        $fingerprint = hash('sha256', json_encode($transaction, JSON_UNESCAPED_SLASHES));
        return $this->store->transaction(function (array &$state) use ($transaction, $fingerprint): array {
            $existing = $state['pegasus_transactions'][$transaction['id']] ?? null;
            if ($existing) {
                if (($existing['fingerprint'] ?? '') !== $fingerprint) {
                    throw new \InvalidArgumentException('vendor_transaction_id was already used with different transaction data.');
                }
                return ['record' => $existing, 'replayed' => true];
            }
            $record = [
                'id' => $transaction['id'],
                'fingerprint' => $fingerprint,
                'transaction_type' => $transaction['type'],
                'amount' => $transaction['amount'],
                'status' => 'PENDING',
                'status_description' => 'Awaiting PegPay response.',
                'created_at' => gmdate('c'),
            ];
            $state['pegasus_transactions'][$transaction['id']] = $record;
            return ['record' => $record, 'replayed' => false];
        });
    }

    private function saveResponse(string $id, array $response): array
    {
        $code = (string) ($response['StatusCode'] ?? $response['Status'] ?? '');
        return $this->update($id, [
            'status' => $code === '0' ? 'SUCCESS' : ($code === '122' ? 'PENDING' : 'FAILED'),
            'status_code' => $code,
            'status_description' => (string) ($response['StatusDescription'] ?? $response['StatusDesc'] ?? 'No status description returned.'),
            'pegpay_id' => $response['PegpayId'] ?? $response['PegPayId'] ?? null,
            'telecom_id' => $response['TelecomID'] ?? null,
            'updated_at' => gmdate('c'),
        ]);
    }

    private function update(string $id, array $changes): array
    {
        return $this->store->transaction(function (array &$state) use ($id, $changes): array {
            $record = $state['pegasus_transactions'][$id] ?? null;
            if (!$record) throw new \RuntimeException('PegPay transaction was not found locally.');
            return $state['pegasus_transactions'][$id] = array_merge($record, $changes);
        });
    }

    private function account(mixed $value): string
    {
        $value = preg_replace('/\s+/', '', trim((string) $value));
        if (!preg_match('/^\d{7,30}$/', $value)) throw new \InvalidArgumentException('account must contain 7-30 digits.');
        return $value;
    }

    private function network(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
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
