<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$name = $argv[1] ?? 'CLI integration';
$key = new \App\Models\ApiKey(new \App\Store());
echo json_encode($key->create($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
