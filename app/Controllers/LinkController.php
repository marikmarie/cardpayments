<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApiKey;
use App\Models\CheckoutSettings;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Services\CyberSourceService;
use App\Store;
use App\View;

final class LinkController extends Controller
{
    private PaymentLink $links;
    private ApiKey $keys;
    private CheckoutSettings $checkoutSettings;

    public function __construct()
    {
        $store = new Store();
        $this->links = new PaymentLink($store);
        $this->keys = new ApiKey($store);
        $this->checkoutSettings = new CheckoutSettings($store);
    }

    public function index(): void
    {
        View::render('links/index', [
            'title' => 'Invoices',
            'links' => $this->links->all(),
            'transactions' => (new Transaction(new Store()))->all(),
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
            $record = $this->createFromInput($input);
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

    public function createFromInput(array $input): array
    {
        $amount = $this->value($input, 'amount');
        $currency = strtoupper($this->value($input, 'currency'));
        $email = $this->value($input, 'customer_email');
        $description = $this->value($input, 'description');
        $send = !empty($input['send']);
        if (!is_numeric($amount) || (float) $amount <= 0 || !$currency || !$description) {
            throw new \InvalidArgumentException('Enter a description, positive amount, and currency.');
        }
        if (($send && !$this->validEmail($email)) || ($email !== '' && !$this->validEmail($email))) {
            throw new \InvalidArgumentException('A valid customer email is required when sending the payment link.');
        }

        $invoiceNumber = $this->value($input, 'invoice_number', 'INV-' . gmdate('YmdHis'));
        if (strlen($invoiceNumber) > 20) {
            throw new \InvalidArgumentException('Invoice number cannot exceed 20 characters.');
        }
        $request = [
            'customer_name' => $this->value($input, 'customer_name', 'Customer'),
            'customer_email' => $email,
            'invoice_number' => $invoiceNumber,
            'description' => $description,
            'due_date' => $this->value($input, 'due_date') ?: gmdate('Y-m-d', strtotime('+7 days')),
            'allow_partial' => !empty($input['allow_partial']),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => $currency,
            'send' => $send,
            'checkout_type' => $this->checkoutSettings->type(),
        ];
        $provider = (new CyberSourceService())->createLink($request);
        if (!$provider['invoice_id'] || !$provider['payment_url']) {
            throw new \RuntimeException('Cybersource created an incomplete invoice response. Check the response log.');
        }
        if ($send) {
            $sent = (new CyberSourceService())->send($provider['invoice_id']);
            $provider['status'] = $sent['status'] ?? 'SENT';
        }

        return $this->links->create($request + [
            'provider_invoice_id' => $provider['invoice_id'],
            'payment_url' => $provider['payment_url'],
            'status' => $provider['status'],
            'provider_data' => $provider['raw'],
        ]);
    }

    private function need(string $id): array
    {
        return $this->links->find($id) ?? throw new \RuntimeException('Payment link not found.');
    }

    private function validEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $suffix = substr(strrchr($domain, '.') ?: '', 1);
        return (bool) preg_match('/^[a-z]{2,63}$/i', $suffix);
    }
}
