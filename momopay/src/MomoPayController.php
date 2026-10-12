<?php
declare(strict_types=1);

namespace MomoPay;

use App\Config;
use App\Controllers\Controller;
use App\Models\ApiKey;
use App\Store;

/** Protected local API wrapper for the MTN MoMo Pay products. */
final class MomoPayController extends Controller
{
    private ApiKey $keys;
    private MomoPayGateway $gateway;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->gateway = new MomoPayGateway($store);
    }

    public function collection(): never { $this->run(fn(array $body) => $this->gateway->collection($body), 202); }
    public function disbursement(): never { $this->run(fn(array $body) => $this->gateway->disbursement($body), 202); }
    public function withdrawal(): never { $this->run(fn(array $body) => $this->gateway->withdrawal($body), 202); }
    public function status(): never { $this->run(fn(array $body) => $this->gateway->status($body)); }
    public function balance(): never { $this->run(fn(array $body) => $this->gateway->balance($body)); }
    public function accountHolder(): never { $this->run(fn(array $body) => $this->gateway->accountHolder($body)); }

    /** Publish the local wrapper operations and their PMT reference contract. */
    public function openApi(): never
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/') ?: $this->requestBaseUrl();
        $paths = [];
        foreach ([
            '/api/v1/momopay/collections' => ['Request customer-authorised collection', '202'],
            '/api/v1/momopay/disbursements' => ['Request disbursement transfer', '202'],
            '/api/v1/momopay/withdrawals' => ['Request enabled collection v2 withdrawal', '202'],
            '/api/v1/momopay/status' => ['Check collection or disbursement status', '200'],
            '/api/v1/momopay/balance' => ['Get collection or disbursement balance', '200'],
            '/api/v1/momopay/account-holder' => ['Check an account holder', '200'],
        ] as $path => [$summary, $success]) {
            $paths[$path] = ['post' => [
                'summary' => $summary,
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => [
                    $success => ['description' => 'MoMo Pay response'],
                    '401' => ['description' => 'Invalid local API key'],
                    '422' => ['description' => 'Invalid request'],
                    '502' => ['description' => 'MTN provider error'],
                    '503' => ['description' => 'MoMo Pay configuration missing'],
                ],
            ]];
        }
        $this->json([
            'openapi' => '3.1.0',
            'info' => ['title' => 'CissyTech MoMo Pay API', 'version' => '1.0.0', 'description' => 'MTN MoMo Pay Collection and Disbursement wrapper. Merchant references use PMT IDs while MTN request IDs use provider UUIDs.'],
            'servers' => [['url' => $base]],
            'security' => [['ApiKeyAuth' => []]],
            'components' => [
                'securitySchemes' => ['ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']],
                'schemas' => ['PaymentReference' => ['type' => 'string', 'pattern' => '^PMT[A-Z0-9]{7}$', 'minLength' => 10, 'maxLength' => 10, 'example' => 'PMT1234567']],
            ],
            'paths' => $paths,
        ]);
    }

    private function run(callable $operation, int $successStatus = 200): never
    {
        $this->apiKey($this->keys);
        try {
            $this->json(['data' => $operation($this->jsonBody())], $successStatus);
        } catch (MomoPayException $error) {
            $this->json(['error' => $error->getMessage()], $error->httpStatus);
        }
    }

    private function requestBaseUrl(): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    }
}
