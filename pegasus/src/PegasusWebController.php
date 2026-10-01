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
        $collections = $this->gateway->recent();
        View::renderModule('pegasus', 'card-collections', [
            'title' => 'Card collections',
            'active_nav' => 'pegasus-card',
            'configured' => $this->gateway->configured(),
            'collections' => $collections,
            'last_collection_id' => $collections[0]['id'] ?? '',
            'status_result' => $_SESSION['pegasus_card_status_result'] ?? null,
            'active_tab' => $_SESSION['pegasus_card_active_tab'] ?? 'collections',
            'log' => $this->gateway->logDetails(),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['pegasus_card_status_result'], $_SESSION['pegasus_card_active_tab'], $_SESSION['flash']);
    }

    public function checkout(array $input): never
    {
        $this->gateway->logCheckoutRequest($input);
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
            $this->gateway->logFailure('CARD_CHECKOUT_FAILED', $e, $input);
            $this->flash('error', $e->getMessage());
            $this->redirect('/pegasus-card');
        }
    }

    public function returned(array $input): never
    {
        $this->gateway->logReturnRequest($input);
        try {
            $result = $this->gateway->receive($input);
            View::renderPublic('checkout/pegasus-return', [
                'title' => 'PegPay payment result',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->gateway->logFailure('CARD_RETURN_FAILED', $e, $input);
            http_response_code(400);
            View::renderPublic('checkout/pegasus-return', [
                'title' => 'PegPay payment result',
                'result' => ['valid' => false, 'status' => '', 'reason' => 'We could not verify this PegPay response.', 'record' => null],
            ]);
        }
        exit;
    }

    public function status(array $input): never
    {
        try {
            $result = $this->gateway->queryStatus($input);
            $_SESSION['pegasus_card_status_result'] = $result;
            $status = $result['record']['status'] ?? 'PENDING';
            $this->flash($status === 'FAILED' ? 'error' : 'success', "PegPay returned {$status} for this card collection.");
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $_SESSION['pegasus_card_active_tab'] = 'status';
        $this->redirect('/pegasus-card');
    }
}
