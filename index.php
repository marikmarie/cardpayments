<?php

require_once __DIR__ . '/CyberSource.php';


define('CS_MERCHANT_ID', 'abug_cissy_1301837_ugx');
define('CS_KEY_ID', '959b814b-514b-417a-8401-f61c17c51402');
define('CS_SHARED_SECRET', 'hDJmkfnGYQlMzsjVlgcnnYiLB0mbX5N8Kb1ZBm7DG6U=');
define('CS_SANDBOX', true);
define('CS_LOG_FILE', __DIR__ . '/cybersource.log');

define('AMOUNT', 5000);


try {
    $cs = new CyberSource(
        CS_MERCHANT_ID,
        CS_KEY_ID,
        CS_SHARED_SECRET,
        CS_SANDBOX,
        CS_LOG_FILE
    );
} catch (\RuntimeException $e) {
    die(json_encode(['error' => $e->getMessage()]) . "\n");
}

//Router
$input = [];
if (php_sapi_name() !== 'cli') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $input = $decoded;
        }
    }

    header('Content-Type: application/json');
}

$action = $_GET['action'] ?? $_POST['action'] ?? $input['action'] ?? ($argv[1] ?? null);
if ($action === null && !empty($input)) {
    if (isset($input['paymentInformation']) || isset($input['orderInformation']) || isset($input['clientReferenceInformation'])) {
        $action = 'raw_payment';
    } else {
        $action = 'authorize';
    }
}

switch ($action) {

    // Authorize a payment (no capture)
    case 'authorize':
        $payload = CyberSource::buildPaymentPayload(
            AMOUNT,
            '4111111111111111',
            '12',
            '2031',
            '123',
            'Cissy',
            'Nakato',
            'cissy@example.co.ug',
            '256772000001',
            'UGX-AUTH-' . time(),
            false
        );

        $result = $cs->createPayment($payload);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    //Auth + Capture in one call 
    case 'auth_capture':
        $payload = CyberSource::buildPaymentPayload(
            AMOUNT,
            '4111111111111111',
            '12',
            '2031',
            '123',
            'Cissy',
            'Nakato',
            'cissy@co.ug',
            '256772000001',
            'UGX-AUTHCAP-' . time(),
            true
        );

        $result = $cs->createPayment($payload);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    case 'raw_payment':
        $payload = $input['payload'] ?? $input;
        if (!is_array($payload) || empty($payload)) {
            echo json_encode(['error' => 'Must supply valid JSON payload for raw_payment'], JSON_PRETTY_PRINT) . "\n";
            break;
        }

        $result = $cs->createPayment($payload);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    // ── 3. Capture a prior authorisation 
    case 'capture':
        $paymentId = $_GET['payment_id'] ?? ($argv[2] ?? '');
        $amount = (int) ($_GET['amount'] ?? ($argv[3] ?? AMOUNT));

        if (!$paymentId) {
            echo json_encode(['error' => 'Missing payment_id — add ?payment_id=XXX']) . "\n";
            break;
        }

        $result = $cs->capturePayment($paymentId, $amount);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    // Void a payment 
    case 'void':
        $paymentId = $_GET['payment_id'] ?? ($argv[2] ?? '');

        if (!$paymentId) {
            echo json_encode(['error' => 'Missing payment_id — add ?payment_id=XXX']) . "\n";
            break;
        }

        $result = $cs->voidPayment($paymentId);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    //Refund a captured/settled payment
    case 'refund':
        $captureId = $_GET['capture_id'] ?? ($argv[2] ?? '');
        $amount = (int) ($_GET['amount'] ?? ($argv[3] ?? AMOUNT));

        if (!$captureId) {
            echo json_encode(['error' => 'Missing capture_id — add ?capture_id=XXX']) . "\n";
            break;
        }

        $result = $cs->refundPayment($captureId, $amount);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    // Get payment details 
    case 'get':
        $paymentId = $_GET['payment_id'] ?? ($argv[2] ?? '');

        if (!$paymentId) {
            echo json_encode(['error' => 'Missing payment_id — add ?payment_id=XXX']) . "\n";
            break;
        }

        $result = $cs->getPayment($paymentId);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;

    default:
        echo json_encode([
            'error' => "Unknown action: '{$action}'",
            'currency' => 'UGX',
            'available' => [
                'authorize' => 'Authorise UGX 50,000 (no capture)',
                'auth_capture' => 'Authorise + capture UGX 150,000',
                'capture' => 'Capture prior auth — needs ?payment_id=',
                'void' => 'Void a payment — needs ?payment_id=',
                'refund' => 'Refund a capture — needs ?capture_id=',
                'get' => 'Get payment details — needs ?payment_id=',
            ],
        ], JSON_PRETTY_PRINT) . "\n";
}