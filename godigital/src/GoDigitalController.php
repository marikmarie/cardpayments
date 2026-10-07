<?php
declare(strict_types=1);

namespace GoDigital;

use App\Config;
use App\Controllers\Controller;
use App\Models\ApiKey;
use App\Store;

/** Local CissyTech API wrapper around the GoDigital mobile-money gateway. */
final class GoDigitalController extends Controller
{
    private ApiKey $keys;
    private GoDigitalGateway $gateway;

    public function __construct()
    {
        $store = new Store();
        $this->keys = new ApiKey($store);
        $this->gateway = new GoDigitalGateway($store);
    }

    public function createCollection(): never
    {
        $this->payment(fn(array $body) => $this->gateway->collection($body, (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')), 201);
    }

    public function createDisbursement(): never
    {
        $this->payment(fn(array $body) => $this->gateway->disbursement($body, (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')), 201);
    }

    public function status(string $reference): never
    {
        $this->apiKey($this->keys);
        try {
            $this->json(['data' => $this->gateway->status($reference)]);
        } catch (GoDigitalException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    public function balance(string $clientId): never
    {
        $this->apiKey($this->keys);
        try {
            $this->json(['data' => $this->gateway->balance($clientId)]);
        } catch (GoDigitalException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    public function nameCheck(): never
    {
        $this->apiKey($this->keys);
        try {
            $this->json(['data' => $this->gateway->nameCheck([
                'provider_code' => $_GET['provider_code'] ?? $_GET['providerCode'] ?? '',
                'msisdn' => $_GET['msisdn'] ?? '',
            ])]);
        } catch (GoDigitalException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    public function openApi(): never
    {
        $baseUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        $this->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CissyTech GoDigital API',
                'version' => '1.0.0',
                'description' => 'Submit GoDigital Tanzania mobile-money collections and disbursements. Collections are asynchronous: confirm their final status from a callback or status lookup.',
            ],
            'servers' => [['url' => $baseUrl !== '' ? $baseUrl : $this->requestBaseUrl()]],
            'security' => [['ApiKeyAuth' => []]],
            'components' => [
                'securitySchemes' => ['ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']],
                'schemas' => [
                    'Payment' => [
                        'type' => 'object',
                        'required' => ['amount', 'provider_code', 'msisdn', 'reference'],
                        'properties' => [
                            'amount' => ['type' => 'string', 'example' => '10000.00'],
                            'provider_code' => ['type' => 'string', 'enum' => ['YAS', 'VODACOM', 'HALOTEL', 'AIRTEL']],
                            'msisdn' => ['type' => 'string', 'example' => '255754123456'],
                            'reference' => ['type' => 'string', 'example' => 'ORDER-1001'],
                            'currency' => ['type' => 'string', 'enum' => ['TZS'], 'default' => 'TZS'],
                            'narration' => ['type' => 'string', 'example' => 'Wallet top-up'],
                            'request_id' => ['type' => 'string', 'description' => 'Optional caller request identifier. Generated when omitted.'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/api/v1/godigital/collections' => ['post' => [
                    'summary' => 'Request a GoDigital C2B mobile-money collection',
                    'description' => 'A 201 means GoDigital accepted the request, not that the customer has paid. Use the callback or status endpoint to determine the final state.',
                    'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']]],
                    'requestBody' => $this->paymentBody(),
                    'responses' => ['201' => ['description' => 'Collection submitted'], '200' => ['description' => 'Previously submitted collection returned'], '401' => ['description' => 'Invalid API key'], '409' => ['description' => 'Idempotency conflict'], '422' => ['description' => 'Invalid request'], '502' => ['description' => 'GoDigital rejected or could not process the request'], '503' => ['description' => 'GoDigital configuration missing']],
                ]],
                '/api/v1/godigital/disbursements' => ['post' => [
                    'summary' => 'Request a GoDigital B2C mobile-money payout',
                    'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']]],
                    'requestBody' => $this->paymentBody(),
                    'responses' => ['201' => ['description' => 'Disbursement submitted'], '200' => ['description' => 'Previously submitted disbursement returned'], '401' => ['description' => 'Invalid API key'], '409' => ['description' => 'Idempotency conflict'], '422' => ['description' => 'Invalid request'], '502' => ['description' => 'GoDigital rejected or could not process the request'], '503' => ['description' => 'GoDigital configuration missing']],
                ]],
                '/api/v1/godigital/payments/{reference}' => ['get' => [
                    'summary' => 'Get the current GoDigital status for a reference',
                    'parameters' => [['name' => 'reference', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => ['description' => 'Provider status'], '401' => ['description' => 'Invalid API key'], '422' => ['description' => 'Invalid reference'], '502' => ['description' => 'Provider error']],
                ]],
                '/api/v1/godigital/wallets/balance/{clientId}' => ['get' => [
                    'summary' => 'Get the GoDigital wallet balance',
                    'parameters' => [['name' => 'clientId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => ['description' => 'Wallet balance'], '401' => ['description' => 'Invalid API key'], '502' => ['description' => 'Provider error']],
                ]],
                '/api/v1/godigital/name-check' => ['get' => [
                    'summary' => 'Verify a mobile-money recipient name',
                    'description' => 'Uses the providerCode and MSISDN query parameters. This endpoint follows GoDigital v1.1.4 and does not use the OAuth payment headers.',
                    'parameters' => [
                        ['name' => 'provider_code', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['YAS', 'VODACOM', 'HALOTEL', 'AIRTEL']]],
                        ['name' => 'msisdn', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'example' => '255754123456']],
                    ],
                    'responses' => ['200' => ['description' => 'Name-check result'], '401' => ['description' => 'Invalid API key'], '422' => ['description' => 'Invalid provider or MSISDN'], '502' => ['description' => 'Provider error']],
                ]],
            ],
        ]);
    }

    private function payment(callable $operation, int $createdStatus): never
    {
        $this->apiKey($this->keys);
        try {
            $result = $operation($this->jsonBody());
            $this->json(['data' => $result], ($result['replayed'] ?? false) ? 200 : $createdStatus);
        } catch (GoDigitalException $e) {
            $this->json(['error' => $e->getMessage()], $e->httpStatus);
        }
    }

    private function paymentBody(): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Payment']]]];
    }

    private function requestBaseUrl(): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    }
}
