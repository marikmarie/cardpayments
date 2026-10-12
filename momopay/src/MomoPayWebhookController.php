<?php
declare(strict_types=1);

namespace MomoPay;

use App\Controllers\Controller;
use App\Store;

/** Public receiver for MTN MoMo Pay collection and disbursement results. */
final class MomoPayWebhookController extends Controller
{
    private MomoPayGateway $gateway;

    public function __construct()
    {
        $this->gateway = new MomoPayGateway(new Store());
    }

    public function health(): never
    {
        $this->json(['status' => 'ok', 'service' => 'momopay-callback']);
    }

    public function receive(): never
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        try {
            $this->json($this->gateway->receiveCallback((string) file_get_contents('php://input'), $headers));
        } catch (MomoPayException $error) {
            $this->json(['error' => $error->getMessage()], $error->httpStatus);
        }
    }
}
