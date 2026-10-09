<?php
declare(strict_types=1);

namespace Pesaway;

use App\Config;
use App\Controllers\Controller;
use App\Models\ApiKey;
use App\Store;

/** Local API wrapper for PesaWay's non-FX, non-crypto integrations. */
final class PesawayController extends Controller
{
    private ApiKey $keys;
    private PesawayGateway $gateway;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->gateway = new PesawayGateway($store);
    }

    public function channels(): never { $this->run(fn(array $body) => $this->gateway->activeChannels($body)); }
    public function mobileB2c(): never { $this->run(fn(array $body) => $this->gateway->mobileB2c($body), 201); }
    public function mobileB2b(): never { $this->run(fn(array $body) => $this->gateway->mobileB2b($body), 201); }
    public function mobileC2b(): never { $this->run(fn(array $body) => $this->gateway->mobileC2b($body), 201); }
    public function authorize(): never { $this->run(fn(array $body) => $this->gateway->authorizeMobile($body)); }
    public function mobileQuery(): never { $this->run(fn(array $body) => $this->gateway->mobileQuery($body)); }
    public function bankPayout(): never { $this->run(fn(array $body) => $this->gateway->bankPayout($body), 201); }
    public function bankQuery(): never { $this->run(fn(array $body) => $this->gateway->bankQuery($body)); }
    public function airtime(): never { $this->run(fn(array $body) => $this->gateway->sendAirtime($body), 201); }
    public function pullTransactions(): never { $this->run(fn(array $body) => $this->gateway->pullTransactions($body)); }
    public function refund(): never { $this->run(fn(array $body) => $this->gateway->refund($body), 201); }
    public function balance(): never { $this->run(fn(array $body) => $this->gateway->balance($body)); }
    public function sendSms(): never { $this->run(fn(array $body) => $this->gateway->sendSms($body), 201); }
    public function smsBalance(): never { $this->run(fn(array $body) => $this->gateway->smsBalance($body)); }

    /** Publish an OpenAPI overview for the protected local PesaWay wrapper. */
    public function openApi(): never
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/') ?: $this->requestBaseUrl();
        $paths = [];
        foreach ([
            '/api/v1/pesaway/channels' => ['Active PesaWay channels'],
            '/api/v1/pesaway/mobile/b2c' => ['Send B2C mobile payment'],
            '/api/v1/pesaway/mobile/b2b' => ['Send B2B mobile payment'],
            '/api/v1/pesaway/mobile/c2b' => ['Request C2B mobile collection'],
            '/api/v1/pesaway/mobile/authorize' => ['Authorise mobile transaction with OTP'],
            '/api/v1/pesaway/mobile/query' => ['Query mobile transaction status'],
            '/api/v1/pesaway/bank/payout' => ['Send bank payout'],
            '/api/v1/pesaway/bank/query' => ['Query bank transaction status'],
            '/api/v1/pesaway/airtime' => ['Send airtime'],
            '/api/v1/pesaway/transactions/pull' => ['Pull historic PesaWay transactions'],
            '/api/v1/pesaway/refunds' => ['Request refund'],
            '/api/v1/pesaway/balance' => ['Get merchant balance'],
            '/api/v1/pesaway/sms' => ['Send SMS'],
            '/api/v1/pesaway/sms/balance' => ['Get SMS balance'],
        ] as $path => [$summary]) {
            $paths[$path] = ['post' => [
                'summary' => $summary,
                'description' => 'PesaWay FX and crypto products are intentionally excluded.',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => [
                    '200' => ['description' => 'PesaWay response'],
                    '201' => ['description' => 'PesaWay accepted the request'],
                    '401' => ['description' => 'Invalid local API key'],
                    '422' => ['description' => 'Invalid request'],
                    '502' => ['description' => 'PesaWay provider error'],
                    '503' => ['description' => 'PesaWay configuration missing'],
                ],
            ]];
        }
        $this->json([
            'openapi' => '3.1.0',
            'info' => ['title' => 'CissyTech PesaWay API', 'version' => '1.0.0', 'description' => 'PesaWay mobile money, banks, airtime, transaction queries, refunds, balances, SMS, and callbacks. FX and crypto are excluded.'],
            'servers' => [['url' => $base]],
            'security' => [['ApiKeyAuth' => []]],
            'components' => [
                'securitySchemes' => ['ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']],
                'schemas' => [
                    'PaymentReference' => ['type' => 'string', 'pattern' => '^PMT[A-Z0-9]{7}$', 'minLength' => 10, 'maxLength' => 10, 'example' => 'PMT1234567'],
                ],
            ],
            'paths' => $paths,
        ]);
    }

    private function run(callable $operation, int $successStatus = 200): never
    {
        $this->apiKey($this->keys);
        try {
            $this->json(['data' => $operation($this->jsonBody())], $successStatus);
        } catch (PesawayException $error) {
            $this->json(['error' => $error->getMessage()], $error->httpStatus);
        }
    }

    private function requestBaseUrl(): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    }
}
