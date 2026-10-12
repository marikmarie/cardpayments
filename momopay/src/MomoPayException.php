<?php
declare(strict_types=1);

namespace MomoPay;

/** A safe, user-facing failure returned by the MTN MoMo Pay gateway. */
final class MomoPayException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
