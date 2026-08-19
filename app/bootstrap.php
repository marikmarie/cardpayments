<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

\App\Config::load(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/cybersource.php';
