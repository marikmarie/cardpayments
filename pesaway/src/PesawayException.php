<?php
declare(strict_types=1);

namespace Pesaway;

/** A safe, user-facing PesaWay integration failure. */
final class PesawayException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
