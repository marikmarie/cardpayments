<?php
declare(strict_types=1);

namespace App\Models;

use App\Store;

final class CheckoutSettings
{
    public function __construct(private Store $store) {}

    public function type(): string
    {
        return $this->normalize($this->store->transaction(
            fn(array $data) => $data['settings']['checkout_type'] ?? 'cissytech',
            false
        ));
    }

    public function setType(string $type): void
    {
        $type = $this->normalize($type);
        $this->store->transaction(function (array &$data) use ($type): void {
            $data['settings']['checkout_type'] = $type;
        });
    }

    private function normalize(mixed $type): string
    {
        return strtolower((string) $type) === 'cybersource' ? 'cybersource' : 'cissytech';
    }
}
