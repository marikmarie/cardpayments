<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApiKey;
use App\Models\CheckoutSettings;
use App\Models\PaymentLink;
use App\Services\CyberSourceService;
use App\Services\PaymentLinkService;
use App\Store;
use App\View;

/** Card invoice dashboard controller. */
final class LinkController extends Controller
{
    private PaymentLink $links;
    private ApiKey $keys;
    private CheckoutSettings $checkoutSettings;
    private PaymentLinkService $paymentLinks;

    public function __construct()
    {
        $store = new Store();
        $this->links = new PaymentLink($store);
        $this->keys = new ApiKey($store);
        $this->checkoutSettings = new CheckoutSettings($store);
        $this->paymentLinks = new PaymentLinkService($store);
    }

    public function index(): void
    {
        View::render('links/index', [
            'title' => 'Invoices',
            'links' => $this->links->all(),
            'flash' => $_SESSION['flash'] ?? null,
            'api_key' => $_SESSION['new_api_key'] ?? null,
            'api_keys' => $this->keys->all(),
            'checkout_type' => $this->checkoutSettings->type(),
        ]);
        unset($_SESSION['flash'], $_SESSION['new_api_key']);
    }

    public function createForm(): void
    {
        View::render('links/create', [
            'title' => 'Create invoice', 'active_nav' => 'create',
            'checkout_type' => $this->checkoutSettings->type(), 'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function create(array $input): void
    {
        try {
            $record = $this->paymentLinks->create($input);
            $this->flash('success', "Invoice {$record['invoice_number']} created.");
            $this->redirect('/links');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/links/create');
        }
    }

    public function send(string $id): void
    {
        try {
            $link = $this->need($id);
            if (!filter_var($link['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Add a valid customer email before sending this payment link.');
            }
            if (!empty($link['due_date']) && $link['due_date'] < gmdate('Y-m-d')) {
                throw new \InvalidArgumentException('This invoice is past its due date. Create a new invoice with a future due date.');
            }
            if (in_array(strtoupper((string) $link['status']), ['PAID', 'COMPLETED', 'CANCELED'], true)) {
                throw new \InvalidArgumentException('This payment link can no longer be emailed.');
            }
            $sent = (new CyberSourceService())->send($link['provider_invoice_id']);
            $this->links->update($id, ['status' => $sent['status'] ?? 'SENT']);
            $this->flash('success', 'Invoice email sent.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/links');
    }

    public function sync(string $id): void
    {
        try {
            $link = $this->need($id);
            $invoice = (new CyberSourceService())->fetch($link['provider_invoice_id']);
            $this->links->update($id, ['status' => $invoice['status'] ?? $link['status'], 'provider_data' => $invoice]);
            $this->flash('success', 'Payment link status refreshed.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/links');
    }

    public function setCheckoutType(array $input): void
    {
        try {
            $type = strtolower($this->value($input, 'checkout_type'));
            if (!in_array($type, ['cissytech', 'cybersource'], true)) {
                throw new \InvalidArgumentException('Choose CissyTech or CyberSource checkout.');
            }
            $this->checkoutSettings->setType($type);
            $this->flash('success', 'Active checkout link updated.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/links');
    }

    public function createApiKey(array $input): void
    {
        $key = $this->keys->create($this->value($input, 'name', 'Integration'));
        $_SESSION['new_api_key'] = $key;
        $this->flash('success', 'Copy this API key now; it is shown only once.');
        $this->redirect('/links');
    }

    public function revokeApiKey(string $id): void
    {
        if ($this->keys->revoke($id)) {
            $this->flash('success', 'API key revoked. Systems using it can no longer call the API.');
        } else {
            $this->flash('error', 'API key not found.');
        }
        $this->redirect('/links');
    }

    private function need(string $id): array
    {
        return $this->links->find($id) ?? throw new \RuntimeException('Payment link not found.');
    }
}
