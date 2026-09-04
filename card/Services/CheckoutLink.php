<?php
declare(strict_types=1);

namespace App\Services;

use App\Config;

/** Card checkout URL selector. */
final class CheckoutLink
{
    public static function type(string $type): string
    {
        return strtolower($type) === 'cybersource' ? 'cybersource' : 'cissytech';
    }

    public static function cissyTechUrl(array $link): string
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
        }
        return $base . '/pay/' . rawurlencode((string) $link['id']);
    }

    public static function selectedUrl(array $link, string $type): string
    {
        return self::type($type) === 'cissytech'
            ? self::cissyTechUrl($link)
            : (string) $link['payment_url'];
    }
}
