<?php
declare(strict_types=1);

namespace MomoPay;

use App\Controllers\Controller;
use App\Store;
use App\View;

/** Browser-only MTN MoMo Pay UAT workspace. */
final class MomoPayTesterController extends Controller
{
    private const TABS = ['connection', 'collection', 'disbursement', 'withdrawal', 'status', 'account', 'sit', 'callbacks', 'logs'];

    private MomoPayGateway $gateway;

    public function __construct()
    {
        $this->gateway = new MomoPayGateway(new Store());
    }

    /** Render the requested test tab. */
    public function index(): void
    {
        $requested = strtolower(trim((string) ($_GET['tab'] ?? '')));
        $tab = in_array($requested, self::TABS, true) ? $requested : ($_SESSION['momopay_active_tab'] ?? 'connection');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'connection';
        }
        View::renderModule('momopay', 'simulator', [
            'title' => 'MoMo Pay',
            'active_nav' => 'momopay',
            'topbar_action' => ['label' => 'MoMo Pay API', 'href' => '/api/v1/momopay/openapi.json'],
            'configuration' => $this->gateway->configuration(),
            'result' => $_SESSION['momopay_result'] ?? null,
            'active_tab' => $tab,
            'last_reference' => $_SESSION['momopay_last_reference'] ?? '',
            'activity' => $this->gateway->activityLog(),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['momopay_result'], $_SESSION['momopay_active_tab'], $_SESSION['flash']);
    }

    public function token(array $input): never { $this->run('connection', fn() => $this->gateway->tokenDetails((string) ($input['product'] ?? ''))); }
    public function collection(array $input): never { $this->payment('collection', fn() => $this->gateway->collection($input), $input, 'MoMo API response recorded. Open Logs for the exact provider response.'); }
    public function disbursement(array $input): never { $this->payment('disbursement', fn() => $this->gateway->disbursement($input), $input, 'MoMo API response recorded. Open Logs for the exact provider response.'); }
    public function withdrawal(array $input): never { $this->payment('withdrawal', fn() => $this->gateway->withdrawal($input), $input, 'MoMo API response recorded. Open Logs for the exact provider response.'); }
    public function status(array $input): never { $this->run('status', fn() => $this->gateway->status($input)); }
    public function balance(array $input): never { $this->run('account', fn() => $this->gateway->balance($input)); }
    public function accountHolder(array $input): never { $this->run('account', fn() => $this->gateway->accountHolder($input)); }
    public function sit(array $input): never { $this->run('sit', fn() => $this->gateway->sit($input)); }

    private function payment(string $tab, callable $operation, array $input, string $success): never
    {
        $this->run($tab, $operation, $success, (string) ($input['reference'] ?? ''));
    }

    /** Keep the last safe response for the appropriate test tab. */
    private function run(string $tab, callable $operation, string $success = 'MoMo API response recorded. Open Logs for the exact provider response.', string $reference = ''): never
    {
        try {
            $_SESSION['momopay_result'] = $operation();
            $_SESSION['momopay_active_tab'] = $tab;
            if ($reference !== '') {
                $_SESSION['momopay_last_reference'] = strtoupper(trim($reference));
            }
            $this->flash('success', $success);
        } catch (MomoPayException $error) {
            $_SESSION['momopay_active_tab'] = $tab;
            $this->flash('error', $error->getMessage());
        } catch (\Throwable $error) {
            $_SESSION['momopay_active_tab'] = $tab;
            $this->flash('error', 'Unable to complete the MoMo Pay request: ' . $error->getMessage());
        }
        $this->redirect('/momopay-tester');
    }
}
