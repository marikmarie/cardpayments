<?php
declare(strict_types=1);

namespace App\Controllers;

abstract class Controller
{
    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = compact('type', 'message');
    }

    protected function redirect(string $path): never
    {
        header('Location: ' . \App\Url::path($path));
        exit;
    }

    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function value(array $input, string $key, string $default = ''): string
    {
        return trim((string) ($input[$key] ?? $default));
    }
}
