<?php
declare(strict_types=1);

// Local PHP-server entrypoint. Production servers should use public/index.php.
require_once __DIR__ . '/bootstrap.php';

(new \App\DevelopmentRouter(__DIR__))->run();
