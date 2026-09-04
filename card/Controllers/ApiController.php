<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApiKey;
use App\Models\CheckoutSettings;
use App\Models\PaymentLink;
use App\Services\CyberSourceService;
use App\Services\CheckoutLink;
use App\Services\PaymentLinkService;
use App\Store;

/** Card API controller. */
final class ApiController extends Controller
{
    private ApiKey $keys;
    private PaymentLink $links;
    private PaymentLinkService $paymentLinks;
    private CheckoutSettings $checkoutSettings;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->links = new PaymentLink($store);
        $this->paymentLinks = new PaymentLinkService($store);
        $this->checkoutSettings = new CheckoutSettings($store);
    }

    public function create(): never
    {
        $this->authorize();
        $payload = $this->body();
        $customer = $payload['customer'] ?? [];
        try {
            $link = $this->paymentLinks->create([
                'amount' => $payload['amount'] ?? '', 'currency' => $payload['currency'] ?? '',
                'invoice_number' => $payload['invoice_number'] ?? '', 'description' => $payload['description'] ?? '',
                'due_date' => $payload['due_date'] ?? '', 'allow_partial' => $payload['allow_partial'] ?? false,
                'send' => $payload['send'] ?? false, 'customer_name' => $customer['name'] ?? '',
                'customer_email' => $customer['email'] ?? '',
            ]);
            $this->json(['data' => $this->createdInvoiceResource($link)], 201);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
    }

    public function show(string $id): never
    {
        $this->authorize();
        $link = $this->links->find($id) ?? $this->links->findByInvoiceNumber($id);
        if (!$link) $this->json(['error' => 'Not found'], 404);

        if (filter_var($_GET['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            try {
                $invoice = (new CyberSourceService())->fetch($link['provider_invoice_id']);
                $link = $this->links->update($link['id'], [
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

    private function createdInvoiceResource(array $link): array
    {
        return [
            'invoice_number' => $link['invoice_number'],
            'payment_url' => CheckoutLink::selectedUrl($link, $this->checkoutSettings->type()),
        ];
    }

}
