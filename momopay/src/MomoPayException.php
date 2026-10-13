<?php
declare(strict_types=1);

namespace MomoPay;

/** A safe, user-facing failure returned by the MTN MoMo Pay gateway. */
final class MomoPayException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details A safe, redacted exchange to render for a rejected SIT call.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        public readonly array $details = [],
    )
    {
        parent::__construct($message);
    }
}
