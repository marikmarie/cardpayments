<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CheckoutSettings;
use App\Models\PaymentLink;
use App\Store;

/** Card module: creates one locally tracked invoice and CyberSource checkout. */
final class PaymentLinkService
{
    private PaymentLink $links;
    private CheckoutSettings $settings;

    public function __construct(private Store $store)
    {
        $this->links = new PaymentLink($store);
        $this->settings = new CheckoutSettings($store);
    }

    public function create(array $input): array
    {
        $amount = trim((string) ($input['amount'] ?? ''));
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $email = trim((string) ($input['customer_email'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $send = !empty($input['send']);

        if (!is_numeric($amount) || (float) $amount <= 0 || $currency === '' || $description === '') {
            throw new \InvalidArgumentException('Enter a description, positive amount, and currency.');
        }
        if (($send && !$this->validEmail($email)) || ($email !== '' && !$this->validEmail($email))) {
            throw new \InvalidArgumentException('A valid customer email is required when sending the payment link.');
        }

        $invoiceNumber = trim((string) ($input['invoice_number'] ?? '')) ?: 'INV-' . gmdate('YmdHis');
        if (strlen($invoiceNumber) > 20) {
            throw new \InvalidArgumentException('Invoice number cannot exceed 20 characters.');
        }

        $request = [
            'customer_name' => trim((string) ($input['customer_name'] ?? '')) ?: 'Customer',
            'customer_email' => $email,
            'invoice_number' => $invoiceNumber,
            'description' => $description,
            'due_date' => trim((string) ($input['due_date'] ?? '')) ?: gmdate('Y-m-d', strtotime('+7 days')),
            'allow_partial' => !empty($input['allow_partial']),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => $currency,
            'send' => $send,
            'checkout_type' => $this->settings->type(),
        ];

        $provider = (new CyberSourceService())->createLink($request);
        if (!$provider['invoice_id'] || !$provider['payment_url']) {
            throw new \RuntimeException('CyberSource created an incomplete invoice response. Check the response log.');
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

    private function validEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $suffix = substr(strrchr($domain, '.') ?: '', 1);
        return (bool) preg_match('/^[a-z]{2,63}$/i', $suffix);
    }
}
