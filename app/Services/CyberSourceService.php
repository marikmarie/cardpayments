<?php
declare(strict_types=1);

namespace App\Services;

use App\Config;

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
        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'Cybersource could not create the payment link.');
        }

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
        if (!$result['success']) throw new \RuntimeException($result['message'] ?? 'Cybersource could not send the invoice.');
        return $result['data'] ?? [];
    }

    public function fetch(string $invoiceId): array
    {
        $result = $this->client->getInvoice($invoiceId);
        if (!$result['success']) throw new \RuntimeException($result['message'] ?? 'Cybersource could not fetch the invoice.');
        return $result['data'] ?? [];
    }

    public function processPayment(array $order): array
    {
        $config = Config::cyberSource();
        $config['mode'] = 'api';
        $result = \CyberSource::init($config)->sale($order);
        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'Cybersource could not process the payment.');
        }

        $data = $result['data'] ?? [];
        return [
            'provider_payment_id' => $result['id'],
            'status' => $result['status'] ?? 'PENDING',
            'processor_transaction_id' => $data['processorInformation']['transactionId'] ?? null,
            'raw' => $data,
        ];
    }
}
