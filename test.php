<?php
require __DIR__ . '/bootstrap.php';


$config = \App\Config::cyberSource();
$config['mode'] = 'api';

try {
    // 3. Initialize the client
    echo "Initializing CyberSource client...\n";
    $cs = CyberSource::init($config);
    echo "Client initialized successfully.\n\n";


    $orderPayload = [
        'amount'    => '100000',
        'currency'  => 'UGX',
        'reference' => 'test_' . time(),
        'card' => [
            'number'          => '4111111111111111',
            'expirationMonth' => '12',
            'expirationYear'  => '2030',
            'securityCode'    => '123'
        ],
        'billTo' => [
            'firstName'          => 'Tukas',
            'lastName'           => 'Mariam',
            'address1'           => '123 Test St',
            'locality'           => 'Kampala',
            'administrativeArea' => 'Central',
            'postalCode'         => '256',
            'country'            => 'UG',
            'email'              => 'mariam@gmail.com'
        ]
    ];


    echo "--- Testing: SALE (Auth + Capture) ---\n";
    $saleResponse = $cs->sale($orderPayload);

    printResponseSummary($saleResponse);

    if (!$saleResponse['success']) {
        throw new Exception("Sale transaction failed. Cannot proceed with Void test.");
    }

    $transactionId = $saleResponse['id'];
    echo "Transaction ID Captured: {$transactionId}\n\n";


    echo "--- Testing: VOID ---\n";
    $voidResponse = $cs->void($transactionId, 'void_' . $orderPayload['reference']);
    printResponseSummary($voidResponse);

} catch (InvalidArgumentException $e) {
    echo "Configuration Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Runtime Error: " . $e->getMessage() . "\n";
}


function printResponseSummary(array $res): void
{
    echo "HTTP Code: " . $res['code'] . "\n";
    echo "Success:   " . ($res['success'] ? 'YES' : 'NO') . "\n";
    echo "Status:    " . ($res['status'] ?? 'N/A') . "\n";
    echo "Message:   " . ($res['message'] ?? 'N/A') . "\n";
    if (!empty($res['error'])) {
        echo "Error:     " . $res['error'] . "\n";
    }
    echo "---------------------------------------\n\n";
}
