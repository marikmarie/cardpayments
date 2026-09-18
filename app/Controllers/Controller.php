<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApiKey;

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

    protected function apiKey(ApiKey $keys): array
    {
        $key = $keys->authenticate($_SERVER['HTTP_X_API_KEY'] ?? null);
        if ($key === null) $this->json(['error' => 'Use a valid X-API-Key header.'], 401);
        return $key;
    }

    protected function jsonBody(): array
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) $this->json(['error' => 'Request body must be valid JSON.'], 400);
        return $body;
    }
}
