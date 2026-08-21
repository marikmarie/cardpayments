<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Models\WebhookEvent;
use App\Models\PaymentLink;
use App\Services\CyberSourceWebhookSignature;
use App\Store;

final class WebhookController extends Controller
{
    public function health(): never
    {
        try {
            (new Store())->read('webhook_events');
            $storage = dirname(__DIR__, 2) . '/storage';
            $this->json([
                'status' => 'ok',
                'database' => 'connected',
                'storage' => is_dir($storage) && is_writable($storage) ? 'writable' : 'not_writable',
            ]);
        } catch (\Throwable) {
            $this->json(['status' => 'unavailable', 'database' => 'unavailable'], 503);
        }
    }

    public function information(): never
    {
        $this->json([
            'message' => 'This endpoint accepts signed POST notifications from CyberSource.',
            'healthCheckUrl' => \App\Url::path('/webhooks/cybersource/health'),
        ], 405);
    }

    public function receive(): never
    {
        $keyId = (string) Config::get('CYBERSOURCE_WEBHOOK_KEY_ID');
        $secret = (string) Config::get('CYBERSOURCE_WEBHOOK_SHARED_SECRET');
        if ($keyId === '' || $secret === '') {
            $this->json(['error' => 'CyberSource webhook signature validation is not configured.'], 503);
        }

        $raw = (string) file_get_contents('php://input');
        $signature = $_SERVER['HTTP_V_C_SIGNATURE'] ?? '';
        $tolerance = (int) Config::get('CYBERSOURCE_WEBHOOK_TOLERANCE_SECONDS', '300');
        if (!CyberSourceWebhookSignature::valid($raw, $signature, $keyId, $secret, $tolerance)) {
            $this->json(['error' => 'Invalid CyberSource webhook signature.'], 401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) $this->json(['error' => 'Invalid JSON'], 400);
        (new WebhookEvent(new Store()))->record($payload);
        $this->applyInvoiceStatus($payload);
        $this->json(['received' => true], 202);
    }

    private function applyInvoiceStatus(array $payload): void
    {
        $event = (string) ($payload['eventType'] ?? '');
        $status = match ($event) {
            'invoicing.customer.invoice.paid' => 'PAID',
            'invoicing.customer.invoice.partial-payment' => 'PARTIALLY_PAID',
            'invoicing.customer.invoice.cancel' => 'CANCELED',
            'invoicing.customer.invoice.send' => 'SENT',
            default => null,
        };
        $invoiceId = $payload['payload']['data']['id']
            ?? $payload['payload']['id']
            ?? $payload['payloads'][0]['data']['id']
            ?? $payload['payloads'][0]['data']['invoiceId']
            ?? null;
        if ($status && is_string($invoiceId)) {
            (new PaymentLink(new Store()))->updateByProviderInvoiceId($invoiceId, ['status' => $status]);
        }
    }
}
