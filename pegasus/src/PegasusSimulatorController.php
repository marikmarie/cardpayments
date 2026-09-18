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
                'title' => 'Collect money', 'type' => 'PULL', 'button' => 'Submit PULL collection',
                'reference' => "PULL-TEST-{$stamp}", 'amount' => '500.00',
                'from_account' => '256772000000', 'from_network' => 'MTN',
                'to_account' => '256702685176', 'to_network' => 'AIRTEL',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'COLLECTION-TEST',
                'narration' => 'PegPay UAT collection',
            ],
            'push' => [
                'title' => 'Send a payout', 'type' => 'PUSH', 'button' => 'Submit PUSH payout',
                'reference' => "PUSH-TEST-{$stamp}", 'amount' => '500.00',
                'from_account' => '256702685176', 'from_network' => 'AIRTEL',
                'to_account' => '256772000000', 'to_network' => 'MTN',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'PAYOUT-TEST',
                'narration' => 'PegPay UAT payout',
            ],
        ];
    }
}
