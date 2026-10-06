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
    private PegasusWebGateway $cardGateway;
    private ApiKey $keys;

    public function __construct()
    {
        $store = new Store();
        $this->pegasus = new PegasusService($store);
        $this->cardGateway = new PegasusWebGateway($store);
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
            $this->json([
                'data' => $this->pegasus->resource($result['record']),
                'replayed' => $result['replayed'],
                'status_checked' => $result['status_checked'] ?? false,
            ], $result['replayed'] ? 200 : 201);
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
        $this->respond(fn() => $this->pegasus->status($id));
    }

    public function balance(): never
    {
        $this->apiKey($this->keys);
        $this->respond(fn() => $this->pegasus->balance());
    }

    /**
     * Create a secure hosted PegPay card session for a trusted integration.
     * The response deliberately contains a CissyTech checkout URL, not the
     * PegPay fields or any secret used to sign them.
     */
    public function createCardCollection(): never
    {
        $this->apiKey($this->keys);
        try {
            $collection = $this->cardGateway->prepare($this->jsonBody());
            $this->json(['data' => $this->cardResource($collection)], 201);
        } catch (\LogicException $e) {
            $this->json(['error' => $e->getMessage()], 503);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->json(['error' => 'Unable to create the card collection.'], 502);
        }
    }

    /** Return a local card status and optionally verify a fresh provider status. */
    public function cardCollection(string $id): never
    {
        $this->apiKey($this->keys);
        try {
            $collection = $this->cardGateway->collection($id);
            $refresh = filter_var($_GET['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($refresh && strtoupper((string) ($collection['status'] ?? 'PENDING')) === 'PENDING') {
                $result = $this->cardGateway->queryStatus(['vendor_transaction_id' => $id]);
                $collection = $result['record'];
            }
            $this->json(['data' => $this->cardResource($collection)]);
        } catch (\LogicException $e) {
            $this->json(['error' => $e->getMessage()], 503);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        } catch (\Throwable) {
            $this->json(['error' => 'Unable to retrieve the card collection.'], 502);
        }
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
                        'vendor_transaction_id' => ['type' => 'string', 'example' => 'COLLECT1001'],
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
                    'responses' => ['200' => ['description' => 'Latest PegPay status, including a provider response for an unknown reference'], '401' => ['description' => 'Invalid API key'], '422' => ['description' => 'Invalid transaction ID'], '502' => ['description' => 'PegPay status check failed']],
                ]],
                '/api/v1/pegasus/balance' => ['get' => [
                    'summary' => 'Get the PegPay vendor account balance',
                    'responses' => ['200' => ['description' => 'PegPay balance response'], '401' => ['description' => 'Invalid API key'], '502' => ['description' => 'PegPay balance request failed']],
                ]],
                '/api/v1/pegasus/card-collections' => ['post' => [
                    'summary' => 'Create a secure hosted PegPay card collection',
                    'description' => 'Returns a CissyTech checkout_url. Open that URL in a browser or secure in-app browser; it submits the signed form to PegPay without exposing card data or credentials to the caller.',
                    'requestBody' => $this->bodySchema(['amount', 'description'], [
                        'amount' => ['type' => 'string', 'example' => '25000'],
                        'currency' => ['type' => 'string', 'enum' => ['UGX', 'USD'], 'default' => 'UGX'],
                        'description' => ['type' => 'string', 'example' => 'Collecto Vault wallet top-up'],
                        'customer_name' => ['type' => 'string', 'example' => 'Mariam Tukas'],
                        'customer_email' => ['type' => 'string', 'format' => 'email'],
                    ]),
                    'responses' => ['201' => ['description' => 'Hosted card collection created'], '401' => ['description' => 'Invalid API key'], '422' => ['description' => 'Invalid request'], '503' => ['description' => 'PegPay Web is not configured']],
                ]],
                '/api/v1/pegasus/card-collections/{vendorTransactionId}' => ['get' => [
                    'summary' => 'Get a hosted PegPay card collection status',
                    'description' => 'Pass refresh=true to query PegPay Web directly for a pending collection. Pending collections cannot be queried more often than every five seconds.',
                    'parameters' => [
                        ['name' => 'vendorTransactionId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'refresh', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                    ],
                    'responses' => ['200' => ['description' => 'Latest secure card collection status'], '401' => ['description' => 'Invalid API key'], '404' => ['description' => 'Collection not found'], '502' => ['description' => 'PegPay status check failed']],
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

    /** Safe representation for API clients. Never return source IP, email, return URL, or provider signatures. */
    private function cardResource(array $collection): array
    {
        return [
            'id' => $collection['id'],
            'amount' => $collection['amount'],
            'currency' => $collection['currency'],
            'description' => $collection['description'],
            'status' => $collection['status'] ?? 'PENDING',
            'reason' => $collection['gateway_reason'] ?? null,
            'provider_transaction_id' => $collection['pegpay_transaction_id'] ?? null,
            'checkout_url' => $this->cardGateway->checkoutUrl($collection),
            'created_at' => $collection['created_at'] ?? null,
            'updated_at' => $collection['updated_at'] ?? null,
        ];
    }
}
