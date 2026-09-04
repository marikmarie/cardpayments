<?php
declare(strict_types=1);

namespace App\Models;

use App\Store;

/** Card webhook-event repository. */
final class WebhookEvent
{
    public function __construct(private Store $store) {}

    public function all(): array
    {
        $events = $this->store->read('webhook_events');
        usort($events, fn(array $a, array $b) => strcmp($b['received_at'], $a['received_at']));
        return $events;
    }

    public function record(array $payload): void
    {
        $this->store->transaction(function (array &$data) use ($payload): void {
            $data['webhook_events'][] = [
                'id' => bin2hex(random_bytes(12)),
                'payload' => $payload,
                'received_at' => gmdate('c'),
            ];
        });
    }
}
