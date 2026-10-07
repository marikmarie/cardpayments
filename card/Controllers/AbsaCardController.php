<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\View;

/** Clear operational workspace for the existing Absa/CyberSource card route. */
final class AbsaCardController extends Controller
{
    public function index(): void
    {
        $configured = [
            'merchant_id' => trim((string) Config::get('CYBERSOURCE_MERCHANT_ID', '')) !== '',
            'key_id' => trim((string) Config::get('CYBERSOURCE_KEY_ID', '')) !== '',
            'shared_secret' => trim((string) Config::get('CYBERSOURCE_SHARED_SECRET', '')) !== '',
            'webhook' => trim((string) Config::get('CYBERSOURCE_WEBHOOK_KEY_ID', '')) !== ''
                && trim((string) Config::get('CYBERSOURCE_WEBHOOK_SHARED_SECRET', '')) !== '',
        ];

        View::render('cards/absa', [
            'title' => 'Absa card payments',
            'active_nav' => 'absa',
            'topbar_action' => ['label' => 'Create card payment', 'href' => '/links/create'],
            'environment' => strtoupper((string) Config::get('CYBERSOURCE_ENV', 'live')),
            'configured' => $configured,
            'log' => \CyberSource::logDetails(),
        ]);
    }
}
