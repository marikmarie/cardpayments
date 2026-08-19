<?php
declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\ApiDocsController;
use App\Controllers\LinkController;
use App\Controllers\TestController;
use App\Controllers\WebhookController;
use App\Url;

require dirname(__DIR__) . '/bootstrap.php';
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

$method = $_SERVER['REQUEST_METHOD'];
$path = Url::withoutBase(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$path = rtrim($path, '/') ?: '/';

try {
    if ($method === 'GET' && $path === '/') { header('Location: ' . Url::path('/links')); exit; }

    if ($method === 'GET' && $path === '/webhooks/cybersource/health') { (new WebhookController())->health(); }
    if ($method === 'POST' && $path === '/webhooks/cybersource') { (new WebhookController())->receive(); }
    if ($method === 'GET' && $path === '/api/v1/openapi.json') { (new ApiDocsController())->openApi(); }
    if ($method === 'POST' && $path === '/api/v1/payment-links') { (new ApiController())->create(); }
    if ($method === 'POST' && $path === '/api/v1/payments') { (new ApiController())->charge(); }
    if ($method === 'GET' && preg_match('#^/api/v1/payment-links/([a-f0-9]+)$#', $path, $m)) { (new ApiController())->show($m[1]); }

    $links = new LinkController();
    $test = new TestController();
    if ($method === 'GET' && $path === '/developers/api') { (new ApiDocsController())->index(); exit; }
    if ($method === 'GET' && $path === '/test-center') { $test->index(); exit; }
    if ($method === 'POST' && preg_match('#^/test-center/links/([a-f0-9]+)/refresh$#', $path, $m)) { $test->refresh($m[1]); }
    if ($method === 'POST' && preg_match('#^/test-center/links/([a-f0-9]+)/email$#', $path, $m)) { $test->send($m[1]); }
    if ($method === 'GET' && $path === '/links') { $links->index(); exit; }
    if ($method === 'GET' && $path === '/links/create') { $links->createForm(); exit; }
    if ($method === 'POST' && $path === '/links') { $links->create($_POST); }
    if ($method === 'POST' && preg_match('#^/links/([a-f0-9]+)/send$#', $path, $m)) { $links->send($m[1]); }
    if ($method === 'POST' && preg_match('#^/links/([a-f0-9]+)/sync$#', $path, $m)) { $links->sync($m[1]); }
    if ($method === 'POST' && $path === '/api-keys') { $links->createApiKey($_POST); }
    if ($method === 'POST' && preg_match('#^/api-keys/([a-f0-9]+)/revoke$#', $path, $m)) { $links->revokeApiKey($m[1]); }

    http_response_code(404);
    echo 'Page not found';
} catch (Throwable $e) {
    http_response_code(500);
    $isApi = str_starts_with($path, '/api/') || str_starts_with($path, '/webhooks/');
    if ($isApi) header('Content-Type: application/json');
    echo $isApi ? json_encode(['error' => $e->getMessage()]) : 'Something went wrong: ' . htmlspecialchars($e->getMessage());
}
