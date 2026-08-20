<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\PaymentLink;
use App\Store;
use App\View;

final class VendorSimulatorController extends Controller
{
    private LinkController $creator;
    private PaymentLink $links;

    public function __construct()
    {
        $this->creator = new LinkController();
        $this->links = new PaymentLink(new Store());
    }

    public function index(): void
    {
        $sessionLink = !empty($_SESSION['vendor_simulator_link_id'])
            ? $this->links->find((string) $_SESSION['vendor_simulator_link_id'])
            : null;

        View::render('vendors/simulator', [
            'title' => 'Vendor simulator',
            'active_nav' => 'vendor',
            'session_link' => $sessionLink,
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function create(array $input): void
    {
        try {
            $link = $this->creator->createFromInput([
                'amount' => $input['amount'] ?? '',
                'currency' => $input['currency'] ?? 'UGX',
                'invoice_number' => $input['invoice_number'] ?? '',
                'description' => $input['description'] ?? '',
                'due_date' => $input['due_date'] ?? '',
                'customer_name' => $input['customer_name'] ?? '',
                'customer_email' => $input['customer_email'] ?? '',
                'allow_partial' => false,
                'send' => false,
            ]);

            $_SESSION['vendor_simulator_link_id'] = $link['id'];
            View::render('vendors/payment-ready', [
                'title' => 'Payment ready',
                'active_nav' => 'vendor',
                'link' => $link,
                'api_response' => json_encode(['data' => $this->resource($link)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/vendor-simulator');
        }
    }

    private function resource(array $link): array
    {
        return [
            'id' => $link['id'],
            'invoice_number' => $link['invoice_number'],
            'provider_invoice_id' => $link['provider_invoice_id'],
            'payment_url' => $link['payment_url'],
            'amount' => $link['amount'],
            'currency' => $link['currency'],
            'status' => $link['status'],
        ];
    }
}
