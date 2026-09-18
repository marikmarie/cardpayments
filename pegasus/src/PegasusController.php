<?php
declare(strict_types=1);

namespace Pegasus;

use App\Config;
use App\Controllers\Controller;
use App\Models\ApiKey;
use App\Store;

/** Public CissyTech API for PegPay collections and payouts. */
final class PegasusController extends Controller
{
    private PegasusService $pegasus;
    private ApiKey $keys;

    public function __construct()
    {
        $store = new Store();
        $this->pegasus = new PegasusService($store);
        $this->keys = new ApiKey($store);
    }

    public function validateRecipient(): never
    {
        $this->apiKey($this->keys);
        $this->respond(fn() => $this->pegasus->validateRecipient($this->jsonBody()));
    }

    public function createTransaction(): never
    {
        $this->apiKey($this->keys);
        try {
            $result = $this->pegasus->createTransaction($this->jsonBody());
            $this->json(['data' => $this->pegasus->resource($result['record']), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
        } catch (\LogicException $e) {
            $this->json(['error' => $e->getMessage()], 503);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    public function transactionStatus(string $id): never
    {
        $this->apiKey($this->keys);
        if (!$this->pegasus->find($id)) $this->json(['error' => 'PegPay transaction not found.'], 404);
        $this->respond(fn() => $this->pegasus->refresh($id));
    }

    public function openApi(): never
    {
        $this->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CissyTech PegPay API',
                'version' => '1.0.0',
                'description' => 'Submit PegPay mobile-money or bank collections and payouts, then retrieve the confirmed provider status.',
            ],
            'servers' => [['url' => $this->baseUrl()]],
            'security' => [['ApiKeyAuth' => []]],
            'components' => ['securitySchemes' => [
                'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ]],
            'paths' => [
                '/api/v1/pegasus/validate-recipient' => ['post' => [
                    'summary' => 'Validate a mobile-money number or bank account',
                    'requestBody' => $this->bodySchema(['account', 'network'], [
                        'account' => ['type' => 'string', 'example' => '256702685176'],
                        'network' => ['type' => 'string', 'example' => 'AIRTEL'],
                    ]),
                    'responses' => ['200' => ['description' => 'Recipient validation result'], '401' => ['description' => 'Invalid API key'], '503' => ['description' => 'PegPay is not configured']],
                ]],
                '/api/v1/pegasus/transactions' => ['post' => [
                    'summary' => 'Create a PegPay collection or payout',
                    'description' => 'Use PULL to collect from from_account. Use PUSH to pay to to_account. Reusing the same vendor_transaction_id is safe only with identical data.',
                    'requestBody' => $this->bodySchema(['transaction_type', 'vendor_transaction_id', 'amount'], [
                        'transaction_type' => ['type' => 'string', 'enum' => ['PULL', 'PUSH'], 'example' => 'PULL'],
                        'vendor_transaction_id' => ['type' => 'string', 'example' => 'COLLECT-1001'],
                        'amount' => ['type' => 'string', 'description' => 'Whole UGX amount.', 'example' => '500'],
                        'from_account' => ['type' => 'string', 'example' => '256772000000'],
                        'from_network' => ['type' => 'string', 'example' => 'MTN'],
                        'to_account' => ['type' => 'string', 'example' => '256702685176'],
                        'to_network' => ['type' => 'string', 'example' => 'AIRTEL'],
                        'customer_name' => ['type' => 'string'],
                        'customer_reference' => ['type' => 'string'],
                        'narration' => ['type' => 'string'],
                        'whitelist' => ['type' => 'string', 'enum' => ['FROMACCOUNT', 'TOACCOUNT', 'BOTH']],
                    ]),
                    'responses' => ['201' => ['description' => 'PegPay request submitted'], '200' => ['description' => 'Previously submitted request returned'], '401' => ['description' => 'Invalid API key'], '422' => ['description' => 'Invalid request'], '502' => ['description' => 'PegPay request failed'], '503' => ['description' => 'PegPay is not configured']],
                ]],
                '/api/v1/pegasus/transactions/{vendorTransactionId}' => ['get' => [
                    'summary' => 'Retrieve the latest PegPay transaction status',
                    'parameters' => [['name' => 'vendorTransactionId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => ['description' => 'Latest PegPay status'], '401' => ['description' => 'Invalid API key'], '404' => ['description' => 'Not found'], '502' => ['description' => 'PegPay status check failed']],
                ]],
            ],
        ]);
    }

    private function respond(callable $operation): never
    {
        try {
            $this->json(['data' => $operation()]);
        } catch (\LogicException $e) {
            $this->json(['error' => $e->getMessage()], 503);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    private function bodySchema(array $required, array $properties): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object', 'required' => $required, 'properties' => $properties,
        ]]]];
    }

    private function baseUrl(): string
    {
        $configured = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($configured !== '') return $configured;
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    }
}
