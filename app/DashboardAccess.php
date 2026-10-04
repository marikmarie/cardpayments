<?php
declare(strict_types=1);

namespace App;

final class DashboardAccess
{
    private const SESSION_KEY = 'dashboard_access_granted';

    public static function configured(): bool
    {
        return self::token() !== '';
    }

    public static function granted(): bool
    {
        return self::configured() && ($_SESSION[self::SESSION_KEY] ?? false) === true;
    }

    public static function authenticate(mixed $submittedToken): bool
    {
        if (!is_scalar($submittedToken)) {
            return false;
        }

        $expectedToken = self::token();
        if ($expectedToken === '' || !hash_equals($expectedToken, trim((string) $submittedToken))) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;

        return true;
    }

    public static function signOut(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['dashboard_next']);
        session_regenerate_id(true);
    }

    public static function needsToken(string $path): bool
    {
        if ($path === '/access' || $path === '/access/logout' || $path === '/assets/app.css') {
            return false;
        }

        if (str_starts_with($path, '/api/') || str_starts_with($path, '/webhooks/')) {
            return false;
        }

        if (preg_match('#^/pay/[a-f0-9]{24}(?:/refresh)?$#', $path) === 1) {
            return false;
        }

        if ($path === '/payment/return' || $path === '/pegasus-card/return') {
            return false;
        }

        return preg_match('#^/pegasus-card/pay/[^/]+$#', $path) !== 1;
    }

    private static function token(): string
    {
        return trim((string) Config::get('DASHBOARD_ACCESS_TOKEN', ''));
    }
}
