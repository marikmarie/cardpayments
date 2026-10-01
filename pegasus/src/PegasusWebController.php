<?php
declare(strict_types=1);

namespace Pegasus;

use App\Controllers\Controller;
use App\Store;
use App\View;

/** Browser checkout for PegPay Web card and mobile-money collections. */
final class PegasusWebController extends Controller
{
    private PegasusWebGateway $gateway;

    public function __construct()
    {
        $this->gateway = new PegasusWebGateway(new Store());
    }

    public function index(): void
    {
        View::renderModule('pegasus', 'card-collections', [
            'title' => 'Card collections',
            'active_nav' => 'pegasus-card',
            'configured' => $this->gateway->configured(),
            'collections' => $this->gateway->recent(),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function checkout(array $input): never
    {
        try {
            $collection = $this->gateway->prepare($input);
            View::renderPublic('checkout/pegasus-redirect', [
                'title' => 'Continue to PegPay',
                'collection' => $collection,
                'gateway_url' => $this->gateway->gatewayUrl(),
                'fields' => $this->gateway->formFields($collection),
            ]);
            exit;
        } catch (\Throwable $e) {
            $this->gateway->logFailure('CARD_CHECKOUT_ERROR', $e);
            $this->flash('error', $e->getMessage());
            $this->redirect('/pegasus-card');
        }
    }

    public function returned(array $input): never
    {
        try {
            $result = $this->gateway->receive($input);
            View::renderPublic('checkout/pegasus-return', [
                'title' => 'PegPay payment result',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->gateway->logFailure('CARD_RETURN_ERROR', $e);
            http_response_code(400);
            View::renderPublic('checkout/pegasus-return', [
                'title' => 'PegPay payment result',
                'result' => ['valid' => false, 'status' => '', 'reason' => 'We could not verify this PegPay response.', 'record' => null],
            ]);
        }
        exit;
    }
}
