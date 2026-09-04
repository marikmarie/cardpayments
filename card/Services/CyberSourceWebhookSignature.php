<?php
declare(strict_types=1);

namespace App\Services;

/** Card module validator for CyberSource webhook signatures. */
final class CyberSourceWebhookSignature
{
    public static function valid(string $rawBody, string $header, string $expectedKeyId, string $base64Secret, int $toleranceSeconds = 300): bool
    {
        $parts = [];
        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) $parts[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
        }

        if (!isset($parts['t'], $parts['keyId'], $parts['sig']) || !ctype_digit($parts['t'])) return false;
        if (!hash_equals($expectedKeyId, $parts['keyId'])) return false;

        $timestamp = (int) $parts['t'];
        $seconds = $timestamp > 9999999999 ? intdiv($timestamp, 1000) : $timestamp;
        if (abs(time() - $seconds) > $toleranceSeconds) return false;

        $secret = base64_decode($base64Secret, true);
        $received = base64_decode($parts['sig'], true);
        if ($secret === false || $received === false) return false;

        $calculated = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret, true);
        return hash_equals($calculated, $received);
    }
}
