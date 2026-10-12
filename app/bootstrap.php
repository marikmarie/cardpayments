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

    if (str_starts_with($class, 'Pegasus\\')) {
        $file = dirname(__DIR__) . '/pegasus/src/' . str_replace('\\', '/', substr($class, strlen('Pegasus\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }

    if (str_starts_with($class, 'GoDigital\\')) {
        $file = dirname(__DIR__) . '/godigital/src/' . str_replace('\\', '/', substr($class, strlen('GoDigital\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }

    if (str_starts_with($class, 'Pesaway\\')) {
        $file = dirname(__DIR__) . '/pesaway/src/' . str_replace('\\', '/', substr($class, strlen('Pesaway\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

\App\Config::load(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/card/cybersource.php';
