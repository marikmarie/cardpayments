<?php
declare(strict_types=1);

namespace GoDigital;

/** A safe, user-facing GoDigital integration failure. */
final class GoDigitalException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
