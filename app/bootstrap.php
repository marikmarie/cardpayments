<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $relative = str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';
        // Shared foundation stays in app/. Payment-specific classes live in card/.
        foreach ([__DIR__ . '/' . $relative, dirname(__DIR__) . '/card/' . $relative] as $file) {
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }

    if (str_starts_with($class, 'Efris\\')) {
        $file = dirname(__DIR__) . '/efris/src/' . str_replace('\\', '/', substr($class, strlen('Efris\\'))) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

\App\Config::load(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/card/cybersource.php';
