<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            self::$values = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        return $env !== false ? $env : (self::$values[$key] ?? $default);
    }

    public static function cyberSource(): array
    {
        $keys = ['CYBERSOURCE_MERCHANT_ID', 'CYBERSOURCE_KEY_ID', 'CYBERSOURCE_SHARED_SECRET'];
        foreach ($keys as $key) {
            if (!self::get($key)) {
                throw new \RuntimeException("Missing {$key} in .env");
            }
        }

        return [
            'mode' => 'payment_link',
            'environment' => self::get('CYBERSOURCE_ENV', 'live'),
            'merchant_id' => self::get('CYBERSOURCE_MERCHANT_ID'),
            'key_id' => self::get('CYBERSOURCE_KEY_ID'),
            'secret_key' => self::get('CYBERSOURCE_SHARED_SECRET'),
            'timeout' => 30,
        ];
    }
}
