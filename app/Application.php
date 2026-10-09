<?php
declare(strict_types=1);

namespace App;

use App\Controllers\AbsaCardController;
use App\Controllers\ApiController;
use App\Controllers\ApiDocsController;
use App\Controllers\CheckoutController;
use App\Controllers\LinkController;
use App\Controllers\VendorSimulatorController;
use App\Controllers\WebhookController;
use Efris\Http\EfrisController;
use Efris\Http\EfrisSimulatorController;
use GoDigital\GoDigitalController;
use GoDigital\GoDigitalTesterController;
use GoDigital\GoDigitalWebhookController;
use Pegasus\PegasusController;
use Pegasus\PegasusSimulatorController;
use Pegasus\PegasusWebController;

/**
 * Application HTTP entrypoint.
 *
 * It owns session setup, dashboard access and route dispatch while controllers
 * retain the payment-specific request handling.
 */
final class Application
{
    /** Start the web application and send the response for the current request. */
    public function run(): never
    {
        $this->startSession();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = Url::withoutBase(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $path = rtrim($path, '/') ?: '/';

        if ($method === 'GET' && $path === '/assets/app.css') {
            $this->stylesheet();
        }

        if ($this->handleAuthentication($method, $path)) {
            exit;
        }

        try {
            if ($this->dispatch($method, $path)) {
                exit;
            }

            http_response_code(404);
            echo 'Page not found';
        } catch (\Throwable $error) {
            $this->renderFailure($path, $error);
        }

        exit;
    }

    /** Configure secure session cookies before any controller reads session data. */
    private function startSession(): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $isHttps,
            'cookie_path' => Url::basePath() ?: '/',
        ]);
    }

    /** Handle the dashboard sign-in flow before protected routes are dispatched. */
    private function handleAuthentication(string $method, string $path): bool
    {
        if ($method === 'GET' && $path === '/login') {
            if (DashboardAccess::granted()) {
                $this->redirect('/links');
            }
            $flash = $_SESSION['dashboard_login_flash'] ?? null;
            unset($_SESSION['dashboard_login_flash']);
            View::renderLogin([
                'title' => 'Sign in',
                'configured' => DashboardAccess::configured(),
                'flash' => $flash,
            ]);
            return true;
        }

        if ($method === 'POST' && $path === '/login') {
            if (DashboardAccess::authenticate($_POST['token'] ?? null)) {
                $next = $_SESSION['dashboard_next'] ?? '/links';
                unset($_SESSION['dashboard_next']);
                $this->redirect(is_string($next) ? $next : '/links');
            }
            $_SESSION['dashboard_login_flash'] = ['type' => 'error', 'message' => 'The access token is not valid.'];
            $this->redirect('/login');
        }

        if ($method === 'POST' && $path === '/login/logout') {
            DashboardAccess::signOut();
            $this->redirect('/login');
        }

        if (DashboardAccess::requiresLogin($path) && !DashboardAccess::granted()) {
            $_SESSION['dashboard_next'] = $path === '/' ? '/links' : $path;
            $this->redirect('/login');
        }

        return false;
    }

    /** Match a controller action to the current method and normalized request path. */
    private function dispatch(string $method, string $path): bool
    {
        if ($method === 'GET' && $path === '/') $this->redirect('/links');

        if ($method === 'GET' && preg_match('#^/pay/(PMT[A-Z0-9]{7}|[a-f0-9]{24})$#i', $path, $match)) { (new CheckoutController())->show($match[1]); return true; }
        if ($method === 'POST' && preg_match('#^/pay/(PMT[A-Z0-9]{7}|[a-f0-9]{24})/refresh$#i', $path, $match)) { (new CheckoutController())->refresh($match[1]); return true; }
        if (in_array($method, ['GET', 'POST'], true) && $path === '/payment/return') { (new CheckoutController())->providerReturn(); return true; }

        if ($method === 'GET' && $path === '/webhooks/cybersource/health') { (new WebhookController())->health(); return true; }
        if ($path === '/webhooks/cybersource') { $webhook = new WebhookController(); if ($method === 'POST') $webhook->receive(); $webhook->information(); return true; }
        if ($method === 'GET' && $path === '/webhooks/godigital/health') { (new GoDigitalWebhookController())->health(); return true; }
        if ($method === 'POST' && $path === '/webhooks/godigital') { (new GoDigitalWebhookController())->receive(); return true; }

        if ($method === 'GET' && $path === '/api/v1/openapi.json') { (new ApiDocsController())->openApi(); return true; }
        if ($method === 'GET' && $path === '/api/v1/godigital/openapi.json') { (new GoDigitalController())->openApi(); return true; }
        if ($method === 'POST' && $path === '/api/v1/godigital/collections') { (new GoDigitalController())->createCollection(); return true; }
        if ($method === 'POST' && $path === '/api/v1/godigital/disbursements') { (new GoDigitalController())->createDisbursement(); return true; }
        if ($method === 'GET' && preg_match('#^/api/v1/godigital/payments/([^/]+)$#', $path, $match)) { (new GoDigitalController())->status(rawurldecode($match[1])); return true; }
        if ($method === 'GET' && preg_match('#^/api/v1/godigital/wallets/balance/([^/]+)$#', $path, $match)) { (new GoDigitalController())->balance(rawurldecode($match[1])); return true; }
        if ($method === 'GET' && $path === '/api/v1/godigital/name-check') { (new GoDigitalController())->nameCheck(); return true; }

        if ($method === 'GET' && $path === '/api/v1/efris/openapi.json') { (new EfrisController())->openApi(); return true; }
        if ($method === 'GET' && $path === '/api/v1/efris/health') { (new EfrisController())->health(); return true; }
        if ($method === 'GET' && $path === '/api/v1/efris/branches') { (new EfrisController())->branches(); return true; }
        if ($method === 'POST' && $path === '/api/v1/efris/invoices') { (new EfrisController())->createInvoice(); return true; }
        if ($method === 'GET' && preg_match('#^/api/v1/efris/invoices/([^/]+)$#', $path, $match)) { (new EfrisController())->showInvoice(rawurldecode($match[1])); return true; }
        if ($method === 'GET' && $path === '/efris-tester') { (new EfrisSimulatorController())->index(); return true; }
        if ($method === 'POST' && $path === '/efris-tester/setup') { (new EfrisSimulatorController())->setup($_POST); return true; }
        if ($method === 'POST' && $path === '/efris-tester/invoices') { (new EfrisSimulatorController())->submit($_POST); return true; }
        if ($method === 'POST' && $path === '/efris-tester/status') { (new EfrisSimulatorController())->status($_POST); return true; }

        if ($method === 'GET' && $path === '/api/v1/pegasus/openapi.json') { (new PegasusController())->openApi(); return true; }
        if ($method === 'GET' && $path === '/api/v1/pegasus/balance') { (new PegasusController())->balance(); return true; }
        if ($method === 'POST' && $path === '/api/v1/pegasus/card-collections') { (new PegasusController())->createCardCollection(); return true; }
        if ($method === 'GET' && preg_match('#^/api/v1/pegasus/card-collections/([^/]+)$#', $path, $match)) { (new PegasusController())->cardCollection(rawurldecode($match[1])); return true; }
        if ($method === 'POST' && $path === '/api/v1/pegasus/validate-recipient') { (new PegasusController())->validateRecipient(); return true; }
        if ($method === 'POST' && $path === '/api/v1/pegasus/transactions') { (new PegasusController())->createTransaction(); return true; }
        if ($method === 'GET' && preg_match('#^/api/v1/pegasus/transactions/([^/]+)$#', $path, $match)) { (new PegasusController())->transactionStatus(rawurldecode($match[1])); return true; }
        if ($method === 'GET' && $path === '/pegasus-tester') { (new PegasusSimulatorController())->index(); return true; }
        if ($method === 'POST' && $path === '/pegasus-tester/verify') { (new PegasusSimulatorController())->verify($_POST); return true; }
        if ($method === 'POST' && $path === '/pegasus-tester/transactions') { (new PegasusSimulatorController())->submit($_POST); return true; }
        if ($method === 'POST' && $path === '/pegasus-tester/status') { (new PegasusSimulatorController())->status($_POST); return true; }
        if ($method === 'POST' && $path === '/pegasus-tester/duplicate') { (new PegasusSimulatorController())->duplicate($_POST); return true; }
        if ($method === 'POST' && $path === '/pegasus-tester/balance') { (new PegasusSimulatorController())->balance(); return true; }
        if ($method === 'GET' && $path === '/pegasus-payouts') { (new PegasusSimulatorController())->payouts(); return true; }
        if ($method === 'POST' && $path === '/pegasus-payouts/review') { (new PegasusSimulatorController())->reviewPayouts($_POST); return true; }
        if ($method === 'POST' && $path === '/pegasus-payouts/send') { (new PegasusSimulatorController())->sendPayouts(); return true; }
        if ($method === 'GET' && $path === '/pegasus-card') { (new PegasusWebController())->index(); return true; }
        if ($method === 'GET' && preg_match('#^/pegasus-card/pay/([A-Za-z0-9_-]{1,60})$#', $path, $match)) { (new PegasusWebController())->pay($match[1]); return true; }
        if ($method === 'POST' && $path === '/pegasus-card/checkout') { (new PegasusWebController())->checkout($_POST); return true; }
        if ($method === 'POST' && $path === '/pegasus-card/status') { (new PegasusWebController())->status($_POST); return true; }
        if (in_array($method, ['GET', 'POST'], true) && $path === '/pegasus-card/return') { (new PegasusWebController())->returned($method === 'POST' ? $_POST : $_GET); return true; }

        if ($method === 'GET' && $path === '/godigital-tester') { (new GoDigitalTesterController())->index(); return true; }
        if ($method === 'POST' && $path === '/godigital-tester/token') { (new GoDigitalTesterController())->token(); return true; }
        if ($method === 'POST' && $path === '/godigital-tester/collection') { (new GoDigitalTesterController())->collection($_POST); return true; }
        if ($method === 'POST' && $path === '/godigital-tester/disbursement') { (new GoDigitalTesterController())->disbursement($_POST); return true; }
        if ($method === 'POST' && $path === '/godigital-tester/status') { (new GoDigitalTesterController())->status($_POST); return true; }
        if ($method === 'POST' && $path === '/godigital-tester/balance') { (new GoDigitalTesterController())->balance($_POST); return true; }
        if ($method === 'POST' && $path === '/godigital-tester/name-check') { (new GoDigitalTesterController())->nameCheck($_POST); return true; }

        if ($method === 'POST' && $path === '/api/v1/payment-links') { (new ApiController())->create(); return true; }
        if ($method === 'GET' && preg_match('#^/api/v1/payment-links/([^/]+)$#', $path, $match)) { (new ApiController())->show(rawurldecode($match[1])); return true; }

        $links = new LinkController();
        $vendor = new VendorSimulatorController();
        if ($method === 'GET' && $path === '/developers/api') { (new ApiDocsController())->index(); return true; }
        if ($method === 'GET' && $path === '/absa-cards') { (new AbsaCardController())->index(); return true; }
        if ($method === 'GET' && $path === '/vendor-simulator') { $vendor->index(); return true; }
        if ($method === 'POST' && $path === '/vendor-simulator/payment-links') { $vendor->create($_POST); return true; }
        if ($method === 'GET' && $path === '/links') { $links->index(); return true; }
        if ($method === 'GET' && $path === '/links/create') { $links->createForm(); return true; }
        if ($method === 'POST' && $path === '/links') { $links->create($_POST); return true; }
        if ($method === 'POST' && preg_match('#^/links/(PMT[A-Z0-9]{7}|[a-f0-9]{24})/send$#i', $path, $match)) { $links->send($match[1]); return true; }
        if ($method === 'POST' && preg_match('#^/links/(PMT[A-Z0-9]{7}|[a-f0-9]{24})/sync$#i', $path, $match)) { $links->sync($match[1]); return true; }
        if ($method === 'POST' && $path === '/settings/checkout-type') { $links->setCheckoutType($_POST); return true; }
        if ($method === 'POST' && $path === '/api-keys') { $links->createApiKey($_POST); return true; }
        if ($method === 'POST' && preg_match('#^/api-keys/(PMT[A-Z0-9]{7}|[a-f0-9]{24})/revoke$#i', $path, $match)) { $links->revokeApiKey($match[1]); return true; }

        return false;
    }

    /** Stream the shared stylesheet when the application is served without a static web server. */
    private function stylesheet(): never
    {
        $stylesheet = dirname(__DIR__) . '/card/assets/app.css';
        if (is_file($stylesheet)) {
            header('Content-Type: text/css; charset=UTF-8');
            readfile($stylesheet);
            exit;
        }
        http_response_code(404);
        exit;
    }

    /** Send a redirect using the configured application base path. */
    private function redirect(string $path): never
    {
        header('Location: ' . Url::path($path));
        exit;
    }

    /** Render a safe error response, exposing details only in explicit debug mode. */
    private function renderFailure(string $path, \Throwable $error): never
    {
        http_response_code(500);
        $isApi = str_starts_with($path, '/api/') || str_starts_with($path, '/webhooks/');
        if ($isApi) {
            header('Content-Type: application/json');
        }
        $debug = filter_var(Config::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
        $message = $debug ? $error->getMessage() : 'An unexpected error occurred. Please try again.';
        echo $isApi ? json_encode(['error' => $message]) : 'Something went wrong: ' . htmlspecialchars($message);
        exit;
    }
}
