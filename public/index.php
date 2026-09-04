<?php
declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\ApiDocsController;
use App\Controllers\CheckoutController;
use App\Controllers\LinkController;
use App\Controllers\VendorSimulatorController;
use App\Controllers\WebhookController;
use App\Url;
use Efris\Http\EfrisController;

require dirname(__DIR__) . '/bootstrap.php';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => $isHttps,
    'cookie_path' => Url::basePath() ?: '/',
]);

$method = $_SERVER['REQUEST_METHOD'];
$path = Url::withoutBase(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$path = rtrim($path, '/') ?: '/';

if ($method === 'GET' && $path === '/assets/app.css') {
    $stylesheet = dirname(__DIR__) . '/card/assets/app.css';
    if (is_file($stylesheet)) {
        header('Content-Type: text/css; charset=UTF-8');
        readfile($stylesheet);
        exit;
    }
}

try {
    if ($method === 'GET' && $path === '/') { header('Location: ' . Url::path('/links')); exit; }

    if ($method === 'GET' && preg_match('#^/pay/([a-f0-9]{24})$#', $path, $m)) { (new CheckoutController())->show($m[1]); exit; }
    if ($method === 'POST' && preg_match('#^/pay/([a-f0-9]{24})/refresh$#', $path, $m)) { (new CheckoutController())->refresh($m[1]); }

    if ($method === 'GET' && $path === '/webhooks/cybersource/health') { (new WebhookController())->health(); }
    if ($path === '/webhooks/cybersource') {
        $webhook = new WebhookController();
        if ($method === 'POST') $webhook->receive();
        $webhook->information();
    }
    if ($method === 'GET' && $path === '/api/v1/openapi.json') { (new ApiDocsController())->openApi(); }
    if ($method === 'GET' && $path === '/api/v1/efris/openapi.json') { (new EfrisController())->openApi(); }
    if ($method === 'GET' && $path === '/api/v1/efris/health') { (new EfrisController())->health(); }
    if ($method === 'GET' && $path === '/api/v1/efris/branches') { (new EfrisController())->branches(); }
    if ($method === 'POST' && $path === '/api/v1/efris/invoices') { (new EfrisController())->createInvoice(); }
    if ($method === 'GET' && preg_match('#^/api/v1/efris/invoices/([^/]+)$#', $path, $m)) {
        (new EfrisController())->showInvoice(rawurldecode($m[1]));
    }
    if ($method === 'POST' && $path === '/api/v1/payment-links') { (new ApiController())->create(); }
    if ($method === 'GET' && preg_match('#^/api/v1/payment-links/([^/]+)$#', $path, $m)) { (new ApiController())->show(rawurldecode($m[1])); }

    $links = new LinkController();
    $vendor = new VendorSimulatorController();
    if ($method === 'GET' && $path === '/developers/api') { (new ApiDocsController())->index(); exit; }
    if ($method === 'GET' && $path === '/vendor-simulator') { $vendor->index(); exit; }
    if ($method === 'POST' && $path === '/vendor-simulator/payment-links') { $vendor->create($_POST); }
    if ($method === 'GET' && $path === '/links') { $links->index(); exit; }
    if ($method === 'GET' && $path === '/links/create') { $links->createForm(); exit; }
    if ($method === 'POST' && $path === '/links') { $links->create($_POST); }
    if ($method === 'POST' && preg_match('#^/links/([a-f0-9]+)/send$#', $path, $m)) { $links->send($m[1]); }
    if ($method === 'POST' && preg_match('#^/links/([a-f0-9]+)/sync$#', $path, $m)) { $links->sync($m[1]); }
    if ($method === 'POST' && $path === '/settings/checkout-type') { $links->setCheckoutType($_POST); }
    if ($method === 'POST' && $path === '/api-keys') { $links->createApiKey($_POST); }
    if ($method === 'POST' && preg_match('#^/api-keys/([a-f0-9]+)/revoke$#', $path, $m)) { $links->revokeApiKey($m[1]); }

    http_response_code(404);
    echo 'Page not found';
} catch (Throwable $e) {
    http_response_code(500);
    $isApi = str_starts_with($path, '/api/') || str_starts_with($path, '/webhooks/');
    if ($isApi) header('Content-Type: application/json');
    $debug = filter_var(\App\Config::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    $message = $debug ? $e->getMessage() : 'An unexpected error occurred. Please try again.';
    echo $isApi ? json_encode(['error' => $message]) : 'Something went wrong: ' . htmlspecialchars($message);
}
