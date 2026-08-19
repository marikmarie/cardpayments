<?php
declare(strict_types=1);

namespace App;

final class Url
{
    public static function basePath(): string
    {
        $path = parse_url((string) Config::get('APP_URL', ''), PHP_URL_PATH);
        if (!is_string($path) || $path === '/' || $path === '') return '';
        return '/' . trim($path, '/');
    }

    public static function path(string $path = '/'): string
    {
        return self::basePath() . '/' . ltrim($path, '/');
    }

    public static function withoutBase(string $path): string
    {
        $base = self::basePath();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return '/' . ltrim($path, '/');
    }
}
