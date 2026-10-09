<?php
declare(strict_types=1);

namespace App;

/**
 * Creates the short payment identifiers used across CissyTech integrations.
 *
 * Provider-issued IDs, opaque security tokens, and historical identifiers are
 * intentionally outside this format because the application does not create
 * them and must continue to accept them when checking earlier payments.
 */
final class PaymentReference
{
    public const PREFIX = 'PMT';
    public const LENGTH = 10;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Generate a new 10-character, uppercase application payment identifier. */
    public static function generate(): string
    {
        $suffix = '';
        $lastIndex = strlen(self::ALPHABET) - 1;
        for ($position = strlen(self::PREFIX); $position < self::LENGTH; $position++) {
            $suffix .= self::ALPHABET[random_int(0, $lastIndex)];
        }

        return self::PREFIX . $suffix;
    }

    /** Return a normalized payment identifier or raise a clear validation error. */
    public static function require(string $value, string $field = 'Payment ID'): string
    {
        $value = strtoupper(trim($value));
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException("{$field} must start with PMT and contain exactly 10 letters or numbers.");
        }

        return $value;
    }

    /** Check whether a value follows the current application payment-ID format. */
    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^PMT[A-Z0-9]{7}$/', strtoupper(trim($value)));
    }
}
