<?php
declare(strict_types=1);

namespace Pegasus;

use App\Controllers\Controller;
use App\Store;
use App\View;

/** Small browser form for Pegasus UAT collections and payouts. */
final class PegasusSimulatorController extends Controller
{
    private PegasusService $pegasus;

    public function __construct()
    {
        $this->pegasus = new PegasusService(new Store());
    }

    public function index(): void
    {
        View::renderModule('pegasus', 'simulator', [
            'title' => 'PegPay test',
            'active_nav' => 'pegasus',
            'samples' => $this->samples(),
            'mobile_networks' => ['MTN' => 'MTN Mobile Money', 'AIRTEL' => 'Airtel Money'],
            'payout_networks' => $this->payoutNetworks(),
            'result' => $_SESSION['pegasus_test_result'] ?? null,
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['pegasus_test_result'], $_SESSION['flash']);
    }

    public function submit(array $input): never
    {
        try {
            $result = $this->pegasus->createTransaction($input);
            $record = $this->pegasus->resource($result['record']);
            $_SESSION['pegasus_test_result'] = ['data' => $record, 'replayed' => $result['replayed']];
            $this->flash($record['status'] === 'FAILED' ? 'error' : 'success', "PegPay returned {$record['status']}." );
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/pegasus-tester');
    }

    private function samples(): array
    {
        $stamp = gmdate('YmdHis');
        return [
            'pull' => [
                'title' => 'Collect money', 'type' => 'PULL', 'channel' => 'mobile', 'button' => 'Submit PULL collection',
                'reference' => "PULL-TEST-{$stamp}", 'amount' => '500',
                'from_account' => '256772000000', 'from_network' => 'MTN',
                'to_account' => '256702685176', 'to_network' => 'AIRTEL',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'COLLECTION-TEST',
                'narration' => 'PegPay UAT collection',
            ],
            'push' => [
                'title' => 'Send a mobile payout', 'type' => 'PUSH', 'channel' => 'mobile', 'button' => 'Submit mobile payout',
                'reference' => "PUSH-TEST-{$stamp}", 'amount' => '500',
                'from_account' => '256702685176', 'from_network' => 'AIRTEL',
                'to_account' => '256772000000', 'to_network' => 'MTN',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'PAYOUT-TEST',
                'narration' => 'PegPay UAT payout',
            ],
            'bank_push' => [
                'title' => 'Send a bank payout', 'type' => 'PUSH', 'channel' => 'bank', 'button' => 'Submit bank payout',
                'reference' => "BANK-PUSH-{$stamp}", 'amount' => '5000',
                'from_account' => '3010000007781', 'from_network' => 'PBU',
                'to_account' => '3010000007781', 'to_network' => 'PBU',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'BANK-PAYOUT-TEST',
                'narration' => 'PegPay UAT bank payout',
            ],
        ];
    }

    /** Bank and mobile codes published in the PegPay integration document. */
    private function payoutNetworks(): array
    {
        return [
            'MTN' => 'MTN Mobile Money', 'AIRTEL' => 'Airtel Money',
            'ABC' => 'ABC Bank', 'ABSA' => 'Absa Bank Uganda', 'BOAU' => 'Bank of Africa',
            'BOB' => 'Bank of Baroda', 'BOI' => 'Bank of India', 'CBU' => 'Cairo Bank Uganda',
            'CNT' => 'Centenary Bank', 'DFCU' => 'DFCU Bank', 'DTB' => 'Diamond Trust Bank',
            'ECO' => 'Ecobank', 'EQU' => 'Equity Bank Uganda', 'EXIM' => 'EXIM Bank',
            'FTB' => 'Finance Trust Bank', 'GTB' => 'Guaranty Trust Bank', 'HFB' => 'Housing Finance Bank',
            'IMBUL' => 'I&M Bank Uganda', 'KCB' => 'Kenya Commercial Bank', 'NCBA' => 'NCBA Bank',
            'OPB' => 'Opportunity Bank', 'PBU' => 'PostBank Uganda', 'STAN' => 'Stanchart Bank',
            'TB' => 'Tropical Bank', 'UMFI' => 'UGAFODE MFI', 'UDB' => 'Uganda Development Bank',
            'UBA' => 'United Bank for Africa',
        ];
    }
}
