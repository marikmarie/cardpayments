<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\PaymentLink;
use App\Services\CheckoutLink;
use App\Services\CyberSourceService;
use App\Store;
use App\View;

final class CheckoutController extends Controller
{
    private PaymentLink $links;

    public function __construct()
    {
        $this->links = new PaymentLink(new Store());
    }

    public function show(string $id): void
    {
        $link = $this->links->find($id);
        if (!$link) {
            http_response_code(404);
            View::renderPublic('checkout/not-found', ['title' => 'Payment link not found']);
            return;
        }

        header('Cache-Control: no-store, private');
        View::renderPublic('checkout/show', [
            'title' => 'Secure payment',
            'link' => $link,
            'cybersource_url' => $link['payment_url'],
            'cissytech_url' => CheckoutLink::cissyTechUrl($link),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function refresh(string $id): never
    {
        try {
            $link = $this->links->find($id);
            if (!$link) throw new \RuntimeException('Payment link not found.');
            $invoice = (new CyberSourceService())->fetch($link['provider_invoice_id']);
            $this->links->update($id, [
                'status' => $invoice['status'] ?? $link['status'],
                'provider_data' => $invoice,
                'refreshed_at' => gmdate('c'),
            ]);
            $this->flash('success', 'Payment status updated.');
        } catch (\Throwable $e) {
            $this->flash('error', 'Unable to update payment status. Please try again.');
        }
        $this->redirect('/pay/' . $id);
    }
}
