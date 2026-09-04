<?php
declare(strict_types=1);

namespace Efris;

use App\Config;
use App\Store;

/** A safe error that the HTTP controller can return without exposing internals. */
final class GatewayException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * CissyTech's tenant-scoped EFRIS gateway.
 *
 * This is deliberately separate from card payments. It owns the vendor-facing
 * contract, tenant/branch resolution, idempotency and the local audit record.
 * URA's encrypted T109 wire protocol is intentionally not guessed here: mock
 * mode is safe for vendor testing until an approved EFRIS protocol adapter is
 * configured from the current URA Interface Design.
 */
final class Gateway
{
    public function __construct(private Store $store) {}

    public function health(): array
    {
        $mode = $this->mode();
        return [
            'status' => 'ok',
            'mode' => $mode,
            'live_transport_ready' => false,
            'message' => $mode === 'mock'
                ? 'Mock mode is ready for vendor integration testing. No data is sent to URA.'
                : 'Live URA transport is disabled until the approved protocol adapter and tenant credentials are configured.',
        ];
    }

    /** Returns only the branches belonging to the API key's tenant. */
    public function branches(array $apiKey): array
    {
        $tenant = $this->tenantFor($apiKey);
        return array_map(static fn(array $branch): array => [
            'code' => $branch['code'],
            'status' => $branch['status'] ?? 'ACTIVE',
        ], $tenant['branches']);
    }

    /**
     * Persist a vendor invoice exactly once. In mock mode it is accepted for
     * end-to-end API testing but never represented as a real URA fiscal record.
     */
    public function fiscalise(array $apiKey, array $input, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') {
            throw new GatewayException(400, 'Idempotency-Key is required.');
        }
        if ($this->mode() !== 'mock') {
            throw new GatewayException(503, 'Live EFRIS submission is not enabled. Complete URA test onboarding and install the approved T109 protocol adapter first.');
        }

        $tenant = $this->tenantFor($apiKey);
        $invoice = $this->validateInvoice($input, $tenant);
        $payloadHash = hash('sha256', json_encode($invoice, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$record, $replayed] = $this->store->transaction(function (array &$data) use ($tenant, $invoice, $idempotencyKey, $payloadHash): array {
            $records = $data['efris_invoices'] ?? [];
            foreach ($records as $existing) {
                $sameTenant = ($existing['tenant_id'] ?? null) === $tenant['id'];
                $sameReference = ($existing['external_reference'] ?? null) === $invoice['external_reference'];
                $sameKey = ($existing['idempotency_key'] ?? null) === $idempotencyKey;

                if ($sameTenant && ($sameReference || $sameKey)) {
                    if (($existing['payload_hash'] ?? null) !== $payloadHash) {
                        throw new GatewayException(409, 'The external reference or Idempotency-Key was already used with different invoice data.');
                    }
                    return [$existing, true];
                }
            }

            $record = [
                'id' => bin2hex(random_bytes(12)),
                'tenant_id' => $tenant['id'],
                'external_reference' => $invoice['external_reference'],
                'branch_code' => $invoice['branch_code'],
                'currency' => $invoice['currency'],
                'total_amount' => $invoice['total_amount'],
                'item_count' => count($invoice['items']),
                'payment_method' => $invoice['payment_method'],
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'local_status' => 'TEST_ACCEPTED',
                'ura_status' => 'NOT_SUBMITTED',
                'fiscal_document_number' => null,
                'verification_code' => null,
                'qr_data' => null,
                'request' => $invoice,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ];
            $data['efris_invoices'][] = $record;
            $data['efris_audit'][] = [
                'id' => bin2hex(random_bytes(12)),
                'tenant_id' => $tenant['id'],
                'action' => 'invoice.mock_accepted',
                'target_id' => $record['id'],
                'created_at' => gmdate('c'),
            ];
            return [$record, false];
        });

        return $this->presentInvoice($record, $replayed, $replayed ? 200 : 202);
    }

    public function invoice(array $apiKey, string $externalReference): ?array
    {
        $tenant = $this->tenantFor($apiKey);
        foreach ($this->store->read('efris_invoices') as $record) {
            if (($record['tenant_id'] ?? null) === $tenant['id']
                && ($record['external_reference'] ?? null) === $externalReference) {
                return $this->presentInvoice($record, false, 200);
            }
        }
        return null;
    }

    /**
     * Safe CLI onboarding. It records identifiers and secret references only;
     * certificates, private keys and AES keys are never stored in this project.
     */
    public function onboard(array $input): array
    {
        $tenantId = strtolower(trim((string) ($input['tenant_id'] ?? '')));
        $apiKeyId = trim((string) ($input['api_key_id'] ?? ''));
        $branchCode = strtoupper(trim((string) ($input['branch_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $tin = trim((string) ($input['tin'] ?? ''));
        $branchId = trim((string) ($input['ura_branch_id'] ?? ''));
        $deviceNumber = trim((string) ($input['device_number'] ?? ''));

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $tenantId)
            || !preg_match('/^[a-f0-9]{24}$/', $apiKeyId)
            || $name === '' || $tin === '' || $branchCode === '' || $branchId === '' || $deviceNumber === '') {
            throw new GatewayException(422, 'tenant_id, api_key_id, name, tin, branch_code, ura_branch_id, and device_number are required.');
        }

        $tenant = $this->store->transaction(function (array &$data) use ($tenantId, $apiKeyId, $branchCode, $name, $tin, $branchId, $deviceNumber): array {
            $keyExists = false;
            foreach ($data['api_keys'] ?? [] as $key) {
                if (($key['id'] ?? null) === $apiKeyId) $keyExists = true;
            }
            if (!$keyExists) throw new GatewayException(404, 'The supplied API key ID does not exist.');

            $tenant = [
                'id' => $tenantId,
                'name' => $name,
                'tin' => $tin,
                'environment' => 'test',
                'status' => 'TEST_CONFIGURED',
                'branches' => [[
                    'code' => $branchCode,
                    'ura_branch_id' => $branchId,
                    'device_number' => $deviceNumber,
                    'status' => 'ACTIVE',
                ]],
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ];

            $tenants = array_values(array_filter(
                $data['efris_tenants'] ?? [],
                static fn(array $row): bool => ($row['id'] ?? null) !== $tenantId
            ));
            $tenants[] = $tenant;
            $data['efris_tenants'] = $tenants;

            $assignments = array_values(array_filter(
                $data['efris_api_key_assignments'] ?? [],
                static fn(array $row): bool => ($row['api_key_id'] ?? null) !== $apiKeyId
            ));
            $assignments[] = ['api_key_id' => $apiKeyId, 'tenant_id' => $tenantId, 'created_at' => gmdate('c')];
            $data['efris_api_key_assignments'] = $assignments;
            return $tenant;
        });

        return [
            'id' => $tenant['id'],
            'name' => $tenant['name'],
            'environment' => $tenant['environment'],
            'status' => $tenant['status'],
            'branch_count' => count($tenant['branches']),
        ];
    }

    private function tenantFor(array $apiKey): array
    {
        $apiKeyId = (string) ($apiKey['id'] ?? '');
        $assignment = null;
        foreach ($this->store->read('efris_api_key_assignments') as $row) {
            if (($row['api_key_id'] ?? null) === $apiKeyId) {
                $assignment = $row;
                break;
            }
        }
        if (!$assignment) {
            throw new GatewayException(403, 'This API key is not assigned to an EFRIS tenant.');
        }

        foreach ($this->store->read('efris_tenants') as $tenant) {
            if (($tenant['id'] ?? null) === ($assignment['tenant_id'] ?? null)) {
                if (($tenant['status'] ?? null) === 'SUSPENDED') {
                    throw new GatewayException(403, 'This EFRIS tenant is suspended.');
                }
                return $tenant;
            }
        }
        throw new GatewayException(403, 'The EFRIS tenant assigned to this API key is unavailable.');
    }

    private function validateInvoice(array $input, array $tenant): array
    {
        $reference = trim((string) ($input['external_reference'] ?? ''));
        $branchCode = strtoupper(trim((string) ($input['branch_code'] ?? '')));
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $total = trim((string) ($input['total_amount'] ?? ''));
        $paymentMethod = strtoupper(trim((string) ($input['payment_method'] ?? '')));
        $items = $input['items'] ?? null;

        if ($reference === '' || strlen($reference) > 100 || $branchCode === ''
            || !preg_match('/^[A-Z]{3}$/', $currency)
            || !preg_match('/^\d{1,15}(\.\d{1,4})?$/', $total)
            || $paymentMethod === '' || !is_array($items) || $items === []) {
            throw new GatewayException(422, 'external_reference, branch_code, total_amount, currency, payment_method, and at least one item are required.');
        }

        $branchFound = false;
        foreach ($tenant['branches'] as $branch) {
            if (($branch['code'] ?? null) === $branchCode && ($branch['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $branchFound = true;
            }
        }
        if (!$branchFound) throw new GatewayException(422, 'branch_code is not active for this API key.');

        $normalItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new GatewayException(422, 'Each item must be an object.');
            $productCode = trim((string) ($item['product_code'] ?? ''));
            $quantity = trim((string) ($item['quantity'] ?? ''));
            $unitPrice = trim((string) ($item['unit_price'] ?? ''));
            if ($productCode === '' || strlen($productCode) > 100
                || !preg_match('/^\d{1,15}(\.\d{1,4})?$/', $quantity)
                || !preg_match('/^\d{1,15}(\.\d{1,4})?$/', $unitPrice)) {
                throw new GatewayException(422, 'Each item needs product_code, quantity, and unit_price as positive decimal strings.');
            }
            $normalItems[] = [
                'product_code' => $productCode,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => trim((string) ($item['discount'] ?? '0')),
            ];
        }

        $buyer = is_array($input['buyer'] ?? null) ? $input['buyer'] : [];
        return [
            'external_reference' => $reference,
            'branch_code' => $branchCode,
            'currency' => $currency,
            'total_amount' => $total,
            'payment_method' => $paymentMethod,
            'buyer' => [
                'type' => trim((string) ($buyer['type'] ?? 'B2C')),
                'name' => trim((string) ($buyer['name'] ?? 'Cash Customer')),
                'tin' => trim((string) ($buyer['tin'] ?? '')),
            ],
            'items' => $normalItems,
        ];
    }

    private function presentInvoice(array $record, bool $replayed, int $httpStatus): array
    {
        return [
            'http_status' => $httpStatus,
            'data' => [
                'id' => $record['id'],
                'external_reference' => $record['external_reference'],
                'branch_code' => $record['branch_code'],
                'total_amount' => $record['total_amount'],
                'currency' => $record['currency'],
                'status' => $record['local_status'],
                'ura_status' => $record['ura_status'],
                'fiscal_document_number' => $record['fiscal_document_number'],
                'verification_code' => $record['verification_code'],
                'qr_data' => $record['qr_data'],
                'test_mode' => true,
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ],
            'meta' => [
                'replayed' => $replayed,
                'message' => 'Mock mode: no invoice was submitted to URA and no fiscal document was issued.',
            ],
        ];
    }

    private function mode(): string
    {
        return strtolower((string) Config::get('EFRIS_MODE', 'mock')) === 'mock' ? 'mock' : 'disabled';
    }
}
