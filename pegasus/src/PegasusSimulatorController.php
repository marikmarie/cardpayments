<?php
declare(strict_types=1);

namespace Pegasus;

use App\Controllers\Controller;
use App\PaymentReference;
use App\Store;
use App\View;

/** Browser workspace for operator-entered PegPay collections and payouts. */
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
            'title' => 'PegPay',
            'active_nav' => 'pegasus',
            'topbar_action' => ['label' => 'Card collections', 'href' => '/pegasus-card'],
            'forms' => $this->forms(),
            'mobile_networks' => ['MTN' => 'MTN Mobile Money', 'AIRTEL' => 'Airtel Money'],
            'payout_networks' => $this->payoutNetworks(),
            'result' => $_SESSION['pegasus_test_result'] ?? null,
            'verification' => $_SESSION['pegasus_verification'] ?? null,
            'status_result' => $_SESSION['pegasus_status_result'] ?? null,
            'balance_result' => $_SESSION['pegasus_balance_result'] ?? null,
            'last_transaction_id' => $_SESSION['pegasus_last_transaction_id'] ?? '',
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
            $result = $this->pegasus->createTransaction($input);
            $record = $this->pegasus->resource($result['record']);
            $statusChecked = $result['status_checked'] ?? false;
            $_SESSION['pegasus_test_result'] = ['data' => $record, 'replayed' => $result['replayed'], 'status_checked' => $statusChecked];
            $_SESSION['pegasus_last_transaction_id'] = $record['vendor_transaction_id'];
            $_SESSION['pegasus_active_tab'] = match ($input['transaction_type'] ?? '') {
                'PULL' => 'collect',
                default => ($input['payment_channel'] ?? '') === 'bank' ? 'bank-payout' : 'mobile-payout',
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
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
                throw new \InvalidArgumentException('Enter a valid vendor transaction ID.');
            }
            $record = $this->pegasus->status($id);
            $_SESSION['pegasus_status_result'] = $record;
            $_SESSION['pegasus_last_transaction_id'] = $id;
            $_SESSION['pegasus_active_tab'] = 'status';
            $this->flash('success', 'PegPay status checked.');
        } catch (\Throwable $e) {
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

    private function forms(): array
    {
        return [
            'pull' => [
                'title' => 'Collect money', 'type' => 'PULL', 'channel' => 'mobile', 'button' => 'Submit collection',
                'reference' => $this->requestId(), 'amount' => '',
                'from_account' => '', 'from_network' => '',
                'to_account' => '', 'to_network' => '',
                'customer_name' => '', 'customer_reference' => '', 'narration' => '',
            ],
            'push' => [
                'title' => 'Send mobile payout', 'type' => 'PUSH', 'channel' => 'mobile', 'button' => 'Send payout',
                'reference' => $this->requestId(), 'amount' => '',
                'from_account' => '', 'from_network' => '',
                'to_account' => '', 'to_network' => '',
                'customer_name' => '', 'customer_reference' => '', 'narration' => '',
            ],
            'bank_push' => [
                'title' => 'Send bank payout', 'type' => 'PUSH', 'channel' => 'bank', 'button' => 'Send payout',
                'reference' => $this->requestId(), 'amount' => '',
                'from_account' => '', 'from_network' => '',
                'to_account' => '', 'to_network' => '',
                'customer_name' => '', 'customer_reference' => '', 'narration' => '',
            ],
        ];
    }

    /** Create the shared 10-character payment ID for a new PegPay request. */
    private function requestId(): string
    {
        return PaymentReference::generate();
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

}
