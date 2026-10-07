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
            'topbar_action' => ['label' => 'Payout batches', 'href' => '/pegasus-payouts'],
            'samples' => $this->samples(),
            'mobile_networks' => ['MTN' => 'MTN Mobile Money', 'AIRTEL' => 'Airtel Money'],
            'payout_networks' => $this->payoutNetworks(),
            'bank_test_accounts' => $this->bankTestAccounts(),
            'result' => $_SESSION['pegasus_test_result'] ?? null,
            'verification' => $_SESSION['pegasus_verification'] ?? null,
            'status_result' => $_SESSION['pegasus_status_result'] ?? null,
            'balance_result' => $_SESSION['pegasus_balance_result'] ?? null,
            'last_transaction_id' => $_SESSION['pegasus_last_transaction_id'] ?? '',
            'last_payout_id' => $_SESSION['pegasus_last_payout_id'] ?? '',
            'log' => $this->pegasus->logDetails(),
            'active_tab' => $_SESSION['pegasus_active_tab'] ?? 'verify',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['pegasus_test_result'], $_SESSION['pegasus_verification'], $_SESSION['pegasus_status_result'], $_SESSION['pegasus_balance_result'], $_SESSION['pegasus_active_tab'], $_SESSION['flash']);
    }

    public function verify(array $input): never
    {
        try {
            $result = $this->pegasus->validateRecipient($input);
            $_SESSION['pegasus_verification'] = $result;
            $_SESSION['pegasus_active_tab'] = 'verify';
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
            if (($input['transaction_type'] ?? '') === 'PUSH') {
                $_SESSION['pegasus_last_payout_id'] = $record['vendor_transaction_id'];
            }
            $_SESSION['pegasus_active_tab'] = match ($input['transaction_type'] ?? '') {
                'PULL' => 'collect',
                default => $testBank === '' ? 'mobile-payout' : 'bank-payout',
            };
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
            if (!empty($input['unknown_scenario'])) {
                $id = $this->requestId('U');
            }
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
                throw new \InvalidArgumentException('Enter a valid vendor transaction ID.');
            }
            $record = $this->pegasus->status($id);
            $_SESSION['pegasus_status_result'] = $record;
            $_SESSION['pegasus_last_transaction_id'] = $id;
            $_SESSION['pegasus_active_tab'] = 'status';
            $this->flash('success', ($record['found_locally'] ?? false) ? 'PegPay status checked.' : 'PegPay status checked for an unknown local reference.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/pegasus-tester');
    }

    public function duplicate(array $input): never
    {
        try {
            $sendToPegPay = !empty($input['send_to_pegpay']);
            $result = $sendToPegPay
                ? $this->pegasus->forceDuplicate((string) ($input['vendor_transaction_id'] ?? ''))
                : $this->pegasus->duplicate((string) ($input['vendor_transaction_id'] ?? ''));
            $record = $this->pegasus->resource($result['record']);
            $_SESSION['pegasus_test_result'] = [
                'data' => $record,
                'replayed' => $result['replayed'] ?? false,
                'duplicate_test' => true,
                'duplicate_sent' => $sendToPegPay,
                'status_checked' => $result['status_checked'] ?? false,
            ];
            $_SESSION['pegasus_last_transaction_id'] = $record['vendor_transaction_id'];
            $_SESSION['pegasus_last_payout_id'] = $record['vendor_transaction_id'];
            $_SESSION['pegasus_active_tab'] = 'duplicate';
            $this->flash('success', $sendToPegPay
                ? 'Duplicate UAT request sent to PegPay. Review its response below and in the Log tab.'
                : ($result['replayed']
                    ? 'Duplicate payout protected. The original transaction was returned without a second payout request.'
                    : 'PegPay requested a documented retry for this payout.'));
        } catch (\Throwable $e) {
            $_SESSION['pegasus_active_tab'] = 'duplicate';
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/pegasus-tester');
    }

    public function balance(): never
    {
        try {
            $result = $this->pegasus->balance();
            $_SESSION['pegasus_balance_result'] = $result;
            $_SESSION['pegasus_active_tab'] = 'balance';
            $success = (string) ($result['StatusCode'] ?? '') === '0';
            $this->flash($success ? 'success' : 'error', $success ? 'PegPay balance retrieved.' : 'PegPay did not return a balance.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/pegasus-tester');
    }

    public function payouts(): void
    {
        View::renderModule('pegasus', 'payouts', [
            'title' => 'Payouts',
            'active_nav' => 'payouts',
            'topbar_action' => ['label' => 'PegPay test', 'href' => '/pegasus-tester'],
            'staff' => $this->staff(),
            'review' => null,
            'log' => $this->pegasus->logDetails(),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function reviewPayouts(array $input): void
    {
        try {
            $review = $this->payoutReview($input);
            $_SESSION['pegasus_payout_review'] = $review;
            View::renderModule('pegasus', 'payouts', [
                'title' => 'Review payouts',
                'active_nav' => 'payouts',
                'topbar_action' => ['label' => 'PegPay test', 'href' => '/pegasus-tester'],
                'staff' => $this->staff(),
                'review' => $review,
                'log' => $this->pegasus->logDetails(),
                'flash' => null,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/pegasus-payouts');
        }
    }

    public function sendPayouts(): void
    {
        $review = $_SESSION['pegasus_payout_review'] ?? null;
        if (!is_array($review) || empty($review['items'])) {
            $this->flash('error', 'Choose staff and review the payout batch first.');
            $this->redirect('/pegasus-payouts');
        }

        $results = [];
        try {
            foreach ($review['items'] as $item) {
                $id = $this->requestId($review['type'] === 'salary' ? 'S' : 'A');
                $response = $this->pegasus->createTransaction([
                    'transaction_type' => 'PUSH',
                    'vendor_transaction_id' => $id,
                    'amount' => $item['amount'],
                    'from_account' => '256702685176',
                    'from_network' => 'AIRTEL',
                    'to_account' => $item['phone'],
                    'to_network' => 'AIRTEL',
                    'customer_name' => $item['name'],
                    'customer_reference' => strtoupper($review['type']) . '-' . $item['id'],
                    'narration' => $review['type'] === 'salary' ? 'Monthly salary UAT' : 'Daily allowance UAT',
                ]);
                $record = $this->pegasus->resource($response['record']);
                $results[] = $item + ['transaction' => $record];
                $_SESSION['pegasus_last_payout_id'] = $record['vendor_transaction_id'];
            }
            unset($_SESSION['pegasus_payout_review']);
            View::renderModule('pegasus', 'payout-results', [
                'title' => 'Payout results',
                'active_nav' => 'payouts',
                'topbar_action' => ['label' => 'PegPay test', 'href' => '/pegasus-tester'],
                'review' => $review,
                'results' => $results,
                'flash' => null,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', 'PegPay stopped the payout batch: ' . $e->getMessage());
            $this->redirect('/pegasus-payouts');
        }
    }

    private function payoutReview(array $input): array
    {
        $type = strtolower(trim((string) ($input['payout_type'] ?? 'allowance')));
        if (!in_array($type, ['allowance', 'salary'], true)) {
            throw new \InvalidArgumentException('Choose daily allowance or monthly salary.');
        }
        $selected = array_flip(array_map('strval', (array) ($input['staff'] ?? [])));
        $items = [];
        foreach ($this->staff() as $person) {
            if (isset($selected[$person['id']])) {
                $items[] = $person + ['amount' => $type === 'salary' ? '5000' : '500'];
            }
        }
        if ($items === []) throw new \InvalidArgumentException('Select at least one staff member.');
        return ['type' => $type, 'items' => $items];
    }

    private function staff(): array
    {
        return [
            ['id' => 'AMINA', 'name' => 'Amina N.', 'department' => 'Finance', 'phone' => '256702685176'],
            ['id' => 'DAVID', 'name' => 'David O.', 'department' => 'Field team', 'phone' => '256702685176'],
            ['id' => 'GRACE', 'name' => 'Grace K.', 'department' => 'Operations', 'phone' => '256702685176'],
        ];
    }

    private function samples(): array
    {
        return [
            'pull' => [
                'title' => 'Collect money', 'type' => 'PULL', 'channel' => 'mobile', 'button' => 'Submit PULL collection',
                'reference' => $this->requestId('P'), 'amount' => '500',
                'from_account' => '256702685176', 'from_network' => 'AIRTEL',
                'to_account' => '', 'to_network' => 'AIRTEL',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'COLLECTION-TEST',
                'narration' => 'PegPay UAT collection',
            ],
            'push' => [
                'title' => 'Pay a staff allowance', 'type' => 'PUSH', 'channel' => 'mobile', 'button' => 'Send allowance payout',
                'reference' => $this->requestId('A'), 'amount' => '500',
                'from_account' => '256702685176', 'from_network' => 'AIRTEL',
                'to_account' => '256702685176', 'to_network' => 'AIRTEL',
                'customer_name' => 'CissyTech Staff', 'customer_reference' => 'MONTHLY-ALLOWANCE',
                'narration' => 'Monthly staff allowance',
            ],
            'bank_push' => [
                'title' => 'Send a bank payout', 'type' => 'PUSH', 'channel' => 'bank', 'button' => 'Submit bank payout',
                'reference' => $this->requestId('B'), 'amount' => '5000',
                'from_account' => '3010000007781', 'from_network' => 'PBU',
                'to_account' => '3010000007781', 'to_network' => 'PBU',
                'customer_name' => 'CissyTech UAT', 'customer_reference' => 'BANK-PAYOUT-TEST',
                'narration' => 'PegPay UAT bank payout',
            ],
        ];
    }

    /** Compact, alphanumeric vendor request IDs for PegPay. */
    private function requestId(string $prefix): string
    {
        return strtoupper($prefix) . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
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
