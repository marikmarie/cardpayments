<?php
declare(strict_types=1);

namespace App\Services;

use App\Config;

/** Card module adapter for CyberSource. */
final class CyberSourceService
{
    private \CyberSource $client;

    public function __construct()
    {
        $this->client = \CyberSource::init(Config::cyberSource());
    }

    public function createLink(array $input): array
    {
        $result = $this->client->createPaymentLink([
            'customerName' => $input['customer_name'],
            'customerEmail' => $input['customer_email'],
            'invoiceNumber' => $input['invoice_number'],
            'description' => $input['description'] ?? '',
            'dueDate' => $input['due_date'] ?? null,
            'allowPartial' => !empty($input['allow_partial']),
            'amount' => $input['amount'],
            'currency' => $input['currency'],
            'send' => !empty($input['send']),
        ]);
        $this->throwIfFailed($result, 'Cybersource could not create the payment link.');

        $data = $result['data'] ?? [];
        return [
            'invoice_id' => $data['id'] ?? $data['invoiceInformation']['id'] ?? null,
            'payment_url' => $result['paymentLink'] ?? $data['invoiceInformation']['paymentLink'] ?? null,
            'status' => $data['status'] ?? 'CREATED',
            'raw' => $data,
        ];
    }

    public function send(string $invoiceId): array
    {
        $result = $this->client->sendInvoice($invoiceId);
        $this->throwIfFailed($result, 'Cybersource could not send the invoice.');
        return $result['data'] ?? [];
    }

    public function fetch(string $invoiceId): array
    {
        $result = $this->client->getInvoice($invoiceId);
        $this->throwIfFailed($result, 'Cybersource could not fetch the invoice.');
        return $result['data'] ?? [];
    }

    private function throwIfFailed(array $result, string $fallback): void
    {
        if (!empty($result['success'])) {
            return;
        }
        $message = (string) ($result['message'] ?? $fallback);
        if (!empty($result['diagnostic'])) {
            $message .= ' ' . $result['diagnostic'];
        }
        if (!empty($result['correlation_id'])) {
            $message .= ' Support ID: ' . $result['correlation_id'] . '.';
        }
        throw new \RuntimeException($message);
    }

}
