<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Models\ApiKey;
use App\Models\CheckoutSettings;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Services\CyberSourceService;
use App\Services\CheckoutLink;
use App\Store;

final class ApiController extends Controller
{
    private ApiKey $keys;
    private PaymentLink $links;
    private Transaction $transactions;
    private LinkController $dashboard;
    private CheckoutSettings $checkoutSettings;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->links = new PaymentLink($store);
        $this->transactions = new Transaction($store);
        $this->dashboard = new LinkController();
        $this->checkoutSettings = new CheckoutSettings($store);
    }

    public function create(): never
    {
        $this->authorize();
        $payload = $this->body();
        $customer = $payload['customer'] ?? [];
        try {
            $link = $this->dashboard->createFromInput([
                'amount' => $payload['amount'] ?? '', 'currency' => $payload['currency'] ?? '',
                'invoice_number' => $payload['invoice_number'] ?? '', 'description' => $payload['description'] ?? '',
                'due_date' => $payload['due_date'] ?? '', 'allow_partial' => $payload['allow_partial'] ?? false,
                'send' => $payload['send'] ?? false, 'customer_name' => $customer['name'] ?? '',
                'customer_email' => $customer['email'] ?? '',
            ]);
            $this->json(['data' => $this->resource($link)], 201);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
    }

    public function show(string $id): never
    {
        $this->authorize();
        $link = $this->links->find($id);
        if (!$link) $this->json(['error' => 'Not found'], 404);

        if (filter_var($_GET['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            try {
                $invoice = (new CyberSourceService())->fetch($link['provider_invoice_id']);
                $link = $this->links->update($id, [
                    'status' => $invoice['status'] ?? $link['status'],
                    'provider_data' => $invoice,
                    'refreshed_at' => gmdate('c'),
                ]) ?? $link;
            } catch (\Throwable $e) {
                $this->json(['error' => 'Could not refresh this invoice from CyberSource: ' . $e->getMessage()], 502);
            }
        }

        $this->json(['data' => $this->resource($link)]);
    }

    public function charge(): never
    {
        $this->authorize();
        if (!filter_var(Config::get('DIRECT_CARD_PAYMENTS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            $this->json(['error' => 'Direct card payments are disabled. Use hosted payment links, or enable this only after PCI DSS approval.'], 403);
        }
        $payload = $this->body();
        $card = $payload['card'] ?? [];
        $billTo = $payload['bill_to'] ?? [];
        $required = ['number', 'expiration_month', 'expiration_year', 'security_code'];
        foreach ($required as $field) {
            if (empty($card[$field])) $this->json(['error' => "card.{$field} is required."], 422);
        }
        if (empty($payload['amount']) || empty($payload['currency'])) {
            $this->json(['error' => 'amount and currency are required.'], 422);
        }
        foreach (['firstName', 'lastName', 'address1', 'locality', 'administrativeArea', 'postalCode', 'country', 'email'] as $field) {
            if (empty($billTo[$field])) $this->json(['error' => "bill_to.{$field} is required for a direct card payment."], 422);
        }

        try {
            $provider = (new CyberSourceService())->processPayment([
                'amount' => (string) $payload['amount'],
                'currency' => strtoupper((string) $payload['currency']),
                'reference' => (string) ($payload['reference'] ?? ('PAY-' . gmdate('YmdHis'))),
                'card' => [
                    'number' => (string) $card['number'],
                    'expirationMonth' => (string) $card['expiration_month'],
                    'expirationYear' => (string) $card['expiration_year'],
                    'securityCode' => (string) $card['security_code'],
                ],
                'billTo' => $this->billTo($billTo),
            ]);
            $transaction = $this->transactions->create([
                'provider_payment_id' => $provider['provider_payment_id'],
                'processor_transaction_id' => $provider['processor_transaction_id'],
                'status' => $provider['status'],
                'reference' => (string) ($payload['reference'] ?? ''),
                'amount' => (string) $payload['amount'],
                'currency' => strtoupper((string) $payload['currency']),
            ]);
            $this->json(['data' => $transaction], 201);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
    }

    private function authorize(): void
    {
        if (!$this->keys->verify($_SERVER['HTTP_X_API_KEY'] ?? null)) {
            $this->json(['error' => 'Use a valid X-API-Key header.'], 401);
        }
    }

    private function body(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) $this->json(['error' => 'Request body must be valid JSON.'], 400);
        return $data;
    }

    private function resource(array $link): array
    {
        $checkoutType = $this->checkoutSettings->type();
        return [
            'id' => $link['id'], 'invoice_number' => $link['invoice_number'], 'provider_invoice_id' => $link['provider_invoice_id'],
            'payment_url' => CheckoutLink::selectedUrl($link, $checkoutType),
            'checkout_type' => $checkoutType,
            'amount' => $link['amount'], 'currency' => $link['currency'],
            'status' => $link['status'], 'created_at' => $link['created_at'], 'updated_at' => $link['updated_at'] ?? null,
            'refreshed_at' => $link['refreshed_at'] ?? null,
        ];
    }

    private function billTo(array $input): array
    {
        $allowed = ['firstName', 'lastName', 'address1', 'locality', 'administrativeArea', 'postalCode', 'country', 'email', 'phoneNumber'];
        return array_intersect_key($input, array_flip($allowed));
    }
}
