<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Models\PaymentLink;
use App\Models\CheckoutSettings;
use App\Models\WebhookEvent;
use App\Services\CyberSourceService;
use App\Store;
use App\View;

final class TestController extends Controller
{
    private PaymentLink $links;

    public function __construct()
    {
        $this->links = new PaymentLink(new Store());
    }

    public function index(): void
    {
        $keyId = (string) Config::get('CYBERSOURCE_WEBHOOK_KEY_ID');
        $secret = (string) Config::get('CYBERSOURCE_WEBHOOK_SHARED_SECRET');
        View::render('links/test-center', [
            'title' => 'Test center',
            'active_nav' => 'test',
            'links' => $this->links->all(),
            'checkout_type' => (new CheckoutSettings(new Store()))->type(),
            'events' => (new WebhookEvent(new Store()))->all(),
            'webhook_ready' => $keyId !== '' && $secret !== '',
            'callback_url' => rtrim((string) Config::get('APP_URL', 'http://localhost:8000'), '/') . '/webhooks/cybersource',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function refresh(string $id): void
    {
        try {
            $link = $this->need($id);
            $invoice = (new CyberSourceService())->fetch($link['provider_invoice_id']);
            $this->links->update($id, [
                'status' => $invoice['status'] ?? $link['status'],
                'provider_data' => $invoice,
                'refreshed_at' => gmdate('c'),
            ]);
            $this->flash('success', 'Status refreshed from CyberSource.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/test-center');
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
        $this->redirect('/test-center');
    }

    private function need(string $id): array
    {
        return $this->links->find($id) ?? throw new \RuntimeException('Payment link not found.');
    }
}
