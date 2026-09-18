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
            'bank_test_accounts' => $this->bankTestAccounts(),
            'result' => $_SESSION['pegasus_test_result'] ?? null,
            'verification' => $_SESSION['pegasus_verification'] ?? null,
            'status_result' => $_SESSION['pegasus_status_result'] ?? null,
            'last_transaction_id' => $_SESSION['pegasus_last_transaction_id'] ?? '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['pegasus_test_result'], $_SESSION['pegasus_verification'], $_SESSION['pegasus_status_result'], $_SESSION['flash']);
    }

    public function verify(array $input): never
    {
        try {
            $result = $this->pegasus->validateRecipient($input);
            $_SESSION['pegasus_verification'] = $result;
            $success = ($result['status_code'] ?? '') === '0';
            $this->flash($success ? 'success' : 'error', $success ? 'Recipient verified.' : 'PegPay could not verify this recipient.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/pegasus-tester');
    }

    public function submit(array $input): never
    {
        try {
            $testBank = trim((string) ($input['bank_test_recipient'] ?? ''));
            if ($testBank !== '') {
                $recipient = $this->bankTestAccounts()[$testBank] ?? null;
                if (!$recipient) throw new \InvalidArgumentException('Choose a valid PegPay bank test recipient.');
                $input['to_account'] = $recipient['account'];
                $input['to_network'] = $recipient['network'];
            }
            $result = $this->pegasus->createTransaction($input);
            $record = $this->pegasus->resource($result['record']);
            $statusChecked = $result['status_checked'] ?? false;
            $_SESSION['pegasus_test_result'] = ['data' => $record, 'replayed' => $result['replayed'], 'status_checked' => $statusChecked];
            $_SESSION['pegasus_last_transaction_id'] = $record['vendor_transaction_id'];
            $message = "PegPay returned {$record['status']}.";
            if ($statusChecked) $message .= ' Status was checked after five seconds.';
            $this->flash($record['status'] === 'FAILED' ? 'error' : 'success', $message);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/pegasus-tester');
    }

    public function status(array $input): never
    {
        try {
            $id = trim((string) ($input['vendor_transaction_id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
                throw new \InvalidArgumentException('Enter a valid vendor transaction ID.');
            }
            $record = $this->pegasus->refresh($id);
            if (!$record) throw new \InvalidArgumentException('This transaction was not created in this tester.');
            $_SESSION['pegasus_status_result'] = $this->pegasus->resource($record);
            $_SESSION['pegasus_last_transaction_id'] = $id;
            $this->flash('success', 'PegPay status checked.');
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
                'from_account' => '256702685176', 'from_network' => 'AIRTEL',
                'to_account' => '', 'to_network' => 'AIRTEL',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'COLLECTION-TEST',
                'narration' => 'PegPay UAT collection',
            ],
            'push' => [
                'title' => 'Send a mobile payout', 'type' => 'PUSH', 'channel' => 'mobile', 'button' => 'Submit mobile payout',
                'reference' => "PUSH-TEST-{$stamp}", 'amount' => '500',
                'from_account' => '256702685176', 'from_network' => 'AIRTEL',
                'to_account' => '256702685176', 'to_network' => 'AIRTEL',
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
            'STANBIC' => 'Stanbic Bank', 'TB' => 'Tropical Bank', 'UMFI' => 'UGAFODE MFI', 'UDB' => 'Uganda Development Bank',
            'UBA' => 'United Bank for Africa',
        ];
    }

    /** Current UAT bank-account pairs supplied for PegPay testing. */
    private function bankTestAccounts(): array
    {
        return [
            'ABC' => ['account' => '020102345678', 'network' => 'ABC', 'name' => 'ABC Bank'],
            'ABSA' => ['account' => '2291325476', 'network' => 'ABSA', 'name' => 'Absa Bank'],
            'DFCU' => ['account' => '01021259311823', 'network' => 'DFCU', 'name' => 'DFCU Bank'],
            'EQU' => ['account' => '1035101840576', 'network' => 'EQU', 'name' => 'Equity Bank'],
            'KCB' => ['account' => '2201256357', 'network' => 'KCB', 'name' => 'KCB Bank'],
            'PBU' => ['account' => '3010000007781', 'network' => 'PBU', 'name' => 'PostBank Uganda'],
            'STANBIC' => ['account' => '9030025270752', 'network' => 'STANBIC', 'name' => 'Stanbic Bank'],
        ];
    }
}
