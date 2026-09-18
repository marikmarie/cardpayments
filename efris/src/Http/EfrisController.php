<?php
declare(strict_types=1);

namespace Efris\Http;

use App\Controllers\Controller;
use App\Config;
use App\Models\ApiKey;
use App\Store;
use Efris\Gateway;
use Efris\GatewayException;

/** Thin HTTP layer for the separate EFRIS gateway module. */
final class EfrisController extends Controller
{
    private ApiKey $keys;
    private Gateway $gateway;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->gateway = new Gateway($store);
    }

    public function health(): never
    {
        $this->json($this->gateway->health());
    }

    public function branches(): never
    {
        try {
            $this->json(['data' => $this->gateway->branches($this->apiKey($this->keys))]);
        } catch (GatewayException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    public function createInvoice(): never
    {
        try {
            $result = $this->gateway->fiscalise(
                $this->apiKey($this->keys),
                $this->jsonBody(),
                (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')
            );
            $status = $result['http_status'];
            unset($result['http_status']);
            $this->json($result, $status);
        } catch (GatewayException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    public function showInvoice(string $externalReference): never
    {
        try {
            $result = $this->gateway->invoice($this->apiKey($this->keys), $externalReference);
            if ($result === null) $this->json(['error' => 'Invoice not found.'], 404);
            $status = $result['http_status'];
            unset($result['http_status']);
            $this->json($result, $status);
        } catch (GatewayException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    public function openApi(): never
    {
        $file = dirname(__DIR__, 3) . '/efris/openapi.json';
        $spec = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $configured = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($configured !== '') $spec['servers'] = [['url' => $configured]];
        $this->json($spec);
    }

}
