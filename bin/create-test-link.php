<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$controller = new \App\Controllers\LinkController();
$link = $controller->createFromInput([
    'amount' => '1000',
    'currency' => 'UGX',
    'invoice_number' => 'PL-' . gmdate('ymdHis'),
    'customer_name' => 'Test Customer',
    'customer_email' => 'test@example.com',
    'description' => 'Cybersource payment-link integration test',
    'send' => false,
]);

echo json_encode($link, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
