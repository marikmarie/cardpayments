<?php
declare(strict_types=1);

namespace Efris\Http;

use App\Controllers\Controller;
use App\Models\ApiKey;
use App\Store;
use App\View;
use Efris\Gateway;
use Efris\GatewayException;

/** Browser-only helper for exercising CissyTech's EFRIS test contract. */
final class EfrisSimulatorController extends Controller
{
    private ApiKey $keys;
    private Gateway $gateway;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->gateway = new Gateway($store);
    }

    public function index(): void
    {
        View::renderModule('efris', 'simulator', [
            'title' => 'EFRIS UAT',
            'active_nav' => 'efris',
            'topbar_action' => ['label' => 'EFRIS API spec', 'href' => '/api/v1/efris/openapi.json'],
            'health' => $this->gateway->health(),
            'activity' => $this->gateway->recentActivity(),
            'api_keys' => $this->keys->all(),
            'setup' => $_SESSION['efris_tester_setup'] ?? [],
            'invoice' => $_SESSION['efris_tester_invoice'] ?? [],
            'last_reference' => $_SESSION['efris_tester_last_reference'] ?? '',
            'result' => $_SESSION['efris_tester_result'] ?? null,
            'status_result' => $_SESSION['efris_tester_status'] ?? null,
            'active_tab' => $_SESSION['efris_tester_tab'] ?? 'setup',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset(
            $_SESSION['efris_tester_result'],
            $_SESSION['efris_tester_status'],
            $_SESSION['efris_tester_tab'],
            $_SESSION['flash']
        );
    }

    /** Saves identifiers for a mock tenant; no secret material is collected. */
    public function setup(array $input): never
    {
        try {
            $this->gateway->onboard($input);
            $_SESSION['efris_tester_setup'] = $this->setupFields($input);
            $_SESSION['efris_tester_tab'] = 'invoice';
            $this->flash('success', 'Test tenant saved. You can now submit a mock invoice.');
        } catch (GatewayException|\InvalidArgumentException $e) {
            $_SESSION['efris_tester_tab'] = 'setup';
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/efris-tester');
    }

    /** Sends a complete invoice through the same mock gateway used by the API. */
    public function submit(array $input): never
    {
        try {
            $key = $this->needKey((string) ($input['api_key_id'] ?? ''));
            $request = [
                'external_reference' => $input['external_reference'] ?? '',
                'branch_code' => $input['branch_code'] ?? '',
                'total_amount' => $input['total_amount'] ?? '',
                'currency' => $input['currency'] ?? 'UGX',
                'payment_method' => $input['payment_method'] ?? 'CASH',
                'buyer' => [
                    'type' => $input['buyer_type'] ?? 'B2C',
                    'name' => $input['buyer_name'] ?? 'Cash Customer',
                    'tin' => $input['buyer_tin'] ?? '',
                ],
                'items' => [[
                    'product_code' => $input['product_code'] ?? '',
                    'quantity' => $input['quantity'] ?? '',
                    'unit_price' => $input['unit_price'] ?? '',
                    'discount' => $input['discount'] ?? '0.00',
                ]],
            ];
            $result = $this->gateway->fiscalise($key, $request, (string) ($input['idempotency_key'] ?? ''));
            $_SESSION['efris_tester_result'] = ['request' => $request, 'response' => $result];
            $_SESSION['efris_tester_invoice'] = $this->invoiceFields($input);
            $_SESSION['efris_tester_last_reference'] = (string) $request['external_reference'];
            $_SESSION['efris_tester_setup'] = array_merge($_SESSION['efris_tester_setup'] ?? [], $this->setupFields($input));
            $_SESSION['efris_tester_tab'] = 'invoice';
            $this->flash('success', ($result['meta']['replayed'] ?? false) ? 'The existing mock invoice was returned.' : 'Mock invoice accepted. Nothing was sent to URA.');
        } catch (GatewayException|\InvalidArgumentException $e) {
            $_SESSION['efris_tester_tab'] = 'invoice';
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/efris-tester');
    }

    public function status(array $input): never
    {
        try {
            $key = $this->needKey((string) ($input['api_key_id'] ?? ''));
            $reference = trim((string) ($input['external_reference'] ?? ''));
            if ($reference === '') throw new \InvalidArgumentException('Enter the invoice reference to check.');
            $result = $this->gateway->invoice($key, $reference);
            if ($result === null) throw new \InvalidArgumentException('No invoice was found for this integration and reference.');
            $_SESSION['efris_tester_status'] = $result;
            $_SESSION['efris_tester_last_reference'] = $reference;
            $_SESSION['efris_tester_tab'] = 'status';
            $this->flash('success', 'Mock invoice status retrieved.');
        } catch (GatewayException|\InvalidArgumentException $e) {
            $_SESSION['efris_tester_tab'] = 'status';
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/efris-tester');
    }

    private function needKey(string $id): array
    {
        $key = $this->keys->find(trim($id));
        if ($key === null) throw new \InvalidArgumentException('Choose a valid CissyTech integration ID.');
        return $key;
    }

    private function setupFields(array $input): array
    {
        $fields = ['tenant_id', 'api_key_id', 'name', 'tin', 'branch_code', 'ura_branch_id', 'device_number'];
        return array_filter(
            array_intersect_key($input, array_flip($fields)),
            static fn(mixed $value): bool => is_scalar($value)
        );
    }

    private function invoiceFields(array $input): array
    {
        $fields = [
            'api_key_id', 'external_reference', 'idempotency_key', 'branch_code', 'total_amount',
            'currency', 'payment_method', 'buyer_type', 'buyer_name', 'buyer_tin',
            'product_code', 'quantity', 'unit_price', 'discount',
        ];
        return array_filter(
            array_intersect_key($input, array_flip($fields)),
            static fn(mixed $value): bool => is_scalar($value)
        );
    }
}
