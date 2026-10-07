<?php
declare(strict_types=1);

namespace GoDigital;

use App\Controllers\Controller;
use App\Store;

/** Public HTTPS receiver used by GoDigital to finalise asynchronous payments. */
final class GoDigitalWebhookController extends Controller
{
    private GoDigitalGateway $gateway;

    public function __construct()
    {
        $this->gateway = new GoDigitalGateway(new Store());
    }

    public function health(): never
    {
        $this->json(['status' => 'ok', 'service' => 'godigital-callback']);
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
        } catch (GoDigitalException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }
}
