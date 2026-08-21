<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\View;

final class ApiDocsController extends Controller
{
    public function index(): void
    {
        View::render('api/docs', [
            'title' => 'API reference',
            'active_nav' => 'api',
            'base_url' => $this->baseUrl(),
        ]);
    }

    public function openApi(): never
    {
        $this->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CissyTech Payments API',
                'version' => '1.0.0',
                'description' => 'Create a CissyTech-branded or direct CyberSource hosted payment link. Card entry always remains on CyberSource.',
            ],
            'servers' => [['url' => $this->baseUrl()]],
            'security' => [['ApiKeyAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
                'schemas' => [
                    'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string']]],
                    'PaymentLinkInput' => [
                        'type' => 'object', 'required' => ['amount', 'currency', 'description'],
                        'properties' => [
                            'amount' => ['type' => 'string', 'example' => '1000.00'],
                            'currency' => ['type' => 'string', 'example' => 'UGX'],
                            'invoice_number' => ['type' => 'string', 'maxLength' => 20, 'example' => 'ORDER-1001'],
                            'description' => ['type' => 'string'],
                            'due_date' => ['type' => 'string', 'format' => 'date'],
                            'send' => ['type' => 'boolean'],
                            'allow_partial' => ['type' => 'boolean'],
                            'customer' => ['type' => 'object', 'description' => 'email is required when send is true', 'properties' => [
                                'name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'],
                            ]],
                        ],
                    ],
                    'PaymentInput' => [
                        'type' => 'object', 'required' => ['amount', 'currency', 'card', 'bill_to'],
                        'properties' => [
                            'amount' => ['type' => 'string', 'example' => '1000.00'],
                            'currency' => ['type' => 'string', 'example' => 'UGX'],
                            'reference' => ['type' => 'string', 'example' => 'ORDER-1002'],
                            'card' => ['type' => 'object', 'required' => ['number', 'expiration_month', 'expiration_year', 'security_code'], 'properties' => [
                                'number' => ['type' => 'string', 'writeOnly' => true],
                                'expiration_month' => ['type' => 'string', 'example' => '12'],
                                'expiration_year' => ['type' => 'string', 'example' => '2031'],
                                'security_code' => ['type' => 'string', 'writeOnly' => true],
                            ]],
                            'bill_to' => ['type' => 'object', 'required' => ['firstName', 'lastName', 'address1', 'locality', 'administrativeArea', 'postalCode', 'country', 'email']],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/api/v1/payments' => ['post' => $this->operation('Process a card payment', 'PaymentInput', 'PaymentResponse')],
                '/api/v1/payment-links' => ['post' => $this->operation('Create a hosted payment link', 'PaymentLinkInput', 'PaymentLinkResponse')],
                '/api/v1/payment-links/{id}' => ['get' => [
                    'summary' => 'Retrieve or refresh a payment-link status',
                    'description' => 'Pass refresh=true after hosted checkout to retrieve the current invoice status directly from CyberSource.',
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'refresh', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean', 'default' => false]],
                    ],
                    'responses' => ['200' => ['description' => 'Payment link'], '401' => ['description' => 'Invalid API key'], '404' => ['description' => 'Not found'], '502' => ['description' => 'CyberSource status refresh failed']],
                ]],
            ],
        ]);
    }

    private function operation(string $summary, string $requestSchema, string $responseName): array
    {
        return [
            'summary' => $summary,
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$requestSchema}"]]]],
            'responses' => [
                '201' => ['description' => 'Created or authorized', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'description' => $responseName]]]]]],
                '400' => ['description' => 'Invalid JSON'],
                '401' => ['description' => 'Invalid API key'],
                '403' => ['description' => 'Direct card payments are disabled'],
                '422' => ['description' => 'Validation or CyberSource error'],
            ],
        ];
    }

    private function baseUrl(): string
    {
        $configured = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($configured !== '') return $configured;
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    }
}
