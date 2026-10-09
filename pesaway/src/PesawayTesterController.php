<?php
declare(strict_types=1);

namespace Pesaway;

use App\Controllers\Controller;
use App\Store;
use App\View;

/** Browser-based PesaWay UAT workspace; credentials stay in .env. */
final class PesawayTesterController extends Controller
{
    private const TABS = ['connection', 'channels', 'mobile', 'bank', 'airtime', 'transactions', 'refunds', 'account', 'sms', 'callbacks', 'logs'];

    private PesawayGateway $gateway;

    public function __construct()
    {
        $this->gateway = new PesawayGateway(new Store());
    }

    /** Render the selected PesaWay operation tab. */
    public function index(): void
    {
        $requested = strtolower(trim((string) ($_GET['tab'] ?? '')));
        $activeTab = in_array($requested, self::TABS, true)
            ? $requested
            : ($_SESSION['pesaway_active_tab'] ?? 'connection');
        if (!in_array($activeTab, self::TABS, true)) {
            $activeTab = 'connection';
        }

        View::renderModule('pesaway', 'simulator', [
            'title' => 'PesaWay',
            'active_nav' => 'pesaway',
            'topbar_action' => ['label' => 'PesaWay API', 'href' => '/api/v1/pesaway/openapi.json'],
            'configuration' => $this->gateway->configuration(),
            'result' => $_SESSION['pesaway_result'] ?? null,
            'active_tab' => $activeTab,
            'last_reference' => $_SESSION['pesaway_last_reference'] ?? '',
            'activity' => $this->gateway->activityLog(),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['pesaway_result'], $_SESSION['pesaway_active_tab'], $_SESSION['flash']);
    }

    public function token(): never { $this->run('connection', fn() => $this->gateway->tokenDetails($_POST), 'OAuth connection accepted by PesaWay.'); }
    public function channels(array $input): never { $this->run('channels', fn() => $this->gateway->activeChannels($input), 'Active channels retrieved.'); }
    public function mobileB2c(array $input): never { $this->payment('mobile', fn() => $this->gateway->mobileB2c($input), $input, 'B2C mobile payment sent.'); }
    public function mobileB2b(array $input): never { $this->payment('mobile', fn() => $this->gateway->mobileB2b($input), $input, 'B2B mobile payment sent.'); }
    public function mobileC2b(array $input): never { $this->payment('mobile', fn() => $this->gateway->mobileC2b($input), $input, 'C2B collection request sent.'); }
    public function authorize(array $input): never { $this->run('mobile', fn() => $this->gateway->authorizeMobile($input), 'Mobile transaction authorisation submitted.'); }
    public function mobileQuery(array $input): never { $this->run('mobile', fn() => $this->gateway->mobileQuery($input), 'Mobile transaction status retrieved.'); }
    public function bankPayout(array $input): never { $this->payment('bank', fn() => $this->gateway->bankPayout($input), $input, 'Bank payout sent.'); }
    public function bankQuery(array $input): never { $this->run('bank', fn() => $this->gateway->bankQuery($input), 'Bank transaction status retrieved.'); }
    public function airtime(array $input): never { $this->payment('airtime', fn() => $this->gateway->sendAirtime($input), $input, 'Airtime request sent.'); }
    public function pull(array $input): never { $this->run('transactions', fn() => $this->gateway->pullTransactions($input), 'Transactions retrieved.'); }
    public function refund(array $input): never { $this->payment('refunds', fn() => $this->gateway->refund($input), $input, 'Refund request sent.'); }
    public function balance(array $input): never { $this->run('account', fn() => $this->gateway->balance($input), 'Merchant balance retrieved.'); }
    public function sendSms(array $input): never { $this->run('sms', fn() => $this->gateway->sendSms($input), 'SMS request sent.'); }
    public function smsBalance(array $input): never { $this->run('sms', fn() => $this->gateway->smsBalance($input), 'SMS balance retrieved.'); }

    private function payment(string $tab, callable $operation, array $input, string $success): never
    {
        $this->run($tab, $operation, $success, (string) ($input['reference'] ?? ''));
    }

    /** Store the latest safe provider response then return to the selected tab. */
    private function run(string $tab, callable $operation, string $success, string $reference = ''): never
    {
        try {
            $_SESSION['pesaway_result'] = $operation();
            $_SESSION['pesaway_active_tab'] = $tab;
            if ($reference !== '') {
                $_SESSION['pesaway_last_reference'] = strtoupper(trim($reference));
            }
            $this->flash('success', $success);
        } catch (PesawayException $error) {
            $_SESSION['pesaway_active_tab'] = $tab;
            $this->flash('error', $error->getMessage());
        } catch (\Throwable $error) {
            $_SESSION['pesaway_active_tab'] = $tab;
            $this->flash('error', 'Unable to complete the PesaWay request: ' . $error->getMessage());
        }
        $this->redirect('/pesaway-tester');
    }
}
