<?php
declare(strict_types=1);

namespace GoDigital;

use App\Controllers\Controller;
use App\Store;
use App\View;

/** Browser-only GoDigital UAT workspace. Credentials stay in the server .env. */
final class GoDigitalTesterController extends Controller
{
    private GoDigitalGateway $gateway;

    public function __construct()
    {
        $this->gateway = new GoDigitalGateway(new Store());
    }

    public function index(): void
    {
        View::renderModule('godigital', 'simulator', [
            'title' => 'GoDigital test',
            'active_nav' => 'godigital',
            'configuration' => $this->gateway->configuration(),
            'result' => $_SESSION['godigital_result'] ?? null,
            'active_tab' => $_SESSION['godigital_active_tab'] ?? 'connection',
            'last_reference' => $_SESSION['godigital_last_reference'] ?? '',
            'activity' => $this->gateway->activityLog(),
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['godigital_result'], $_SESSION['godigital_active_tab'], $_SESSION['flash']);
    }

    public function token(): never
    {
        $this->run('connection', fn() => $this->gateway->tokenDetails(), 'OAuth token accepted by GoDigital.');
    }

    public function collection(array $input): never
    {
        $this->run('collection', fn() => $this->gateway->collection($input), 'Collection request sent. Confirm it with the callback or status tab.', $input['reference'] ?? '');
    }

    public function disbursement(array $input): never
    {
        $this->run('disbursement', fn() => $this->gateway->disbursement($input), 'Disbursement request sent. Confirm it with the callback or status tab.', $input['reference'] ?? '');
    }

    public function status(array $input): never
    {
        $reference = $this->value($input, 'reference');
        $this->run('status', fn() => $this->gateway->status($reference), 'Status retrieved.', $reference);
    }

    public function balance(array $input): never
    {
        $this->run('balance', fn() => $this->gateway->balance($this->value($input, 'client_id')), 'Wallet balance retrieved.');
    }

    private function run(string $tab, callable $operation, string $success, string $reference = ''): never
    {
        try {
            $_SESSION['godigital_result'] = $operation();
            $_SESSION['godigital_active_tab'] = $tab;
            if ($reference !== '') {
                $_SESSION['godigital_last_reference'] = trim($reference);
            }
            $this->flash('success', $success);
        } catch (GoDigitalException $e) {
            $_SESSION['godigital_active_tab'] = $tab;
            $this->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $_SESSION['godigital_active_tab'] = $tab;
            $this->flash('error', 'Unable to complete the GoDigital request: ' . $e->getMessage());
        }
        $this->redirect('/godigital-tester');
    }
}
