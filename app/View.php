<?php
declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $name, array $data = []): void
    {
        $data['url'] ??= [Url::class, 'path'];
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . "/views/{$name}.php";
        $content = ob_get_clean();
        require dirname(__DIR__) . '/views/layout.php';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
