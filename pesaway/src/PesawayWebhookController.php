<?php
declare(strict_types=1);

namespace Pesaway;

use App\Controllers\Controller;
use App\Store;

/** Public receiver for asynchronous PesaWay payment and refund callbacks. */
final class PesawayWebhookController extends Controller
{
    private PesawayGateway $gateway;

    public function __construct()
    {
        $this->gateway = new PesawayGateway(new Store());
    }

    public function health(): never
    {
        $this->json(['status' => 'ok', 'service' => 'pesaway-callback']);
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
        } catch (PesawayException $error) {
            $this->json(['error' => $error->getMessage()], $error->httpStatus);
        }
    }
}
