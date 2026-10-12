<?php
class MOMOPAYAPI {    
    private static $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    private static $primaryKey = '';
    private static $secondaryKey = '';
    private static $callback = '';
    private static $env = 'Sandbox';
    
    private static $apiUrl = [
        "Sandbox" => "https://sandbox.momodeveloper.mtn.com/v1_0/",
        "Live" => ""
    ];
    
    /**
     * Initialize the MOMOPAYAPI with required credentials
     */
    public static function init(
        string $primaryKey,
        string $secondaryKey,
        string $callbackUrl,
        string $env = 'Sandbox'
    ) {
        self::$primaryKey = $primaryKey;
        self::$secondaryKey = $secondaryKey;
        self::$callback = $callbackUrl;
        self::$env = $env;
    }
    
    /**
     * Check if the class has been initialized
     */
    private static function checkInitialization() {
        if (empty(self::$primaryKey) || empty(self::$callback)) {
            throw new Exception('MOMOPAYAPI not initialized. Call init() first.');
        }
    }
    
    public static function requestToPay($currency, $amount, $phone, $message, $externalId, $version = null) {
        self::checkInitialization();        
        $resp = [];
        //Step1 - Create user
        $referenceId = self::GUUID4(); //Save it in a database or file for future reference
        $url1 = self::$apiUrl[self::$env] . 'apiuser';
        $requestHeaders1 = self::$headers;
        $requestHeaders1[] = "X-Reference-Id: " . $referenceId;
        $requestHeaders1[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $requestData1 = json_encode([
            "providerCallbackHost" => self::$callback
        ]);        
        $call1 = self::CURLRequest($url1, $requestHeaders1, $requestData1);
        if ($call1['Code'] === 201) {            
            // Step 2 - Get user
            $url2 = self::$apiUrl[self::$env] . 'apiuser/' . $referenceId;
            $requestHeaders2 = self::$headers;
            $requestHeaders2[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
            $call2 = self::CURLRequest($url2, $requestHeaders2);
            if ($call2['Code'] === 200) {                
                // Step3 Get ApiKey
                $url3 = self::$apiUrl[self::$env] . 'apiuser/' . trim($referenceId) . '/apikey';
                $requestHeaders3 = self::$headers;
                $requestHeaders3[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                //Pass Empty data request
                $call3 = self::CURLRequest($url3, $requestHeaders3, json_encode([]));
                if ($call3['Code'] === 201) {
                    $apiKey = $call3['Details']->apiKey;
                    //Step4 - Get Access Token
                    $url4 = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'collection/token/';
                    $requestHeaders4 = self::$headers;
                    $requestHeaders4[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                    $basicAuth = $referenceId . ':' . $apiKey;
                    $call4 = self::CURLRequest($url4, $requestHeaders4, json_encode([]), $basicAuth);
                    if ($call4['Code'] === 200) {
                        $accessToken = $call4['Details']->access_token;
                        if ($version) {
                            if ($version == 2) {
                                $url5 = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'collection/v2_0/requesttowithdraw';
                            }
                        } else {
                            $url5 = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'collection/v1_0/requesttopay';
                        }
                        $requestHeaders5 = self::$headers;
                        $requestHeaders5[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                        $requestHeaders5[] = "X-Reference-Id: " . $referenceId;
                        $requestHeaders5[] = "X-Target-Environment: " . strtolower(self::$env);
                        $requestHeaders5[] = "Authorization: Bearer " . $accessToken;
                        $requestData5 = json_encode([
                            "amount" => $amount,
                            "currency" => $currency,
                            "externalId" => $externalId,
                            "payer" => [
                                "partyIdType" => "MSISDN",
                                "partyId" => $phone
                            ],
                            "payerMessage" => $message['message'],
                            "payeeNote" => $message['note']
                        ]);
                        $call5 = self::CURLRequest($url5, $requestHeaders5, $requestData5);
                        if ($call5['Code'] === 202) {
                            $resp['requestToPay'] = true;
                            $resp['referenceId'] = $referenceId;
                            $resp['apiKey'] = $apiKey;
                            $resp['response'] = $call5['Details'];
                        } else {
                            $resp['requestToPay'] = false;
                            $resp['message1'] = 'Request Not Sent';
                            $resp['message'] = 'ERROR #R2P0005';
                            $resp['response'] = $call5;
                        }                        
                    } else {
                        $resp['requestToPay'] = false;
                        $resp['message1'] = 'Access Token Not Gotten';
                        $resp['message'] = 'ERROR #R2P0004';
                        $resp['response'] = $call4;
                    }
                } else {
                    $resp['requestToPay'] = false;
                    $resp['message1'] = 'ApiKey Not Gotten';
                    $resp['message'] = 'ERROR #R2P0003';
                    $resp['response'] = $call3;
                }                
            } else {
                $resp['requestToPay'] = false;
                $resp['message1'] = 'User Not Gotten';
                $resp['message'] = 'ERROR #R2P0002';
                $resp['response'] = $call2;
            }            
        } else {
            $resp['requestToPay'] = false;
            $resp['message1'] = 'User Not Created';
            $resp['message'] = 'ERROR #R2P0001';
            $resp['response'] = $call1;
        }
        
        return $resp;
    }

    public static function payOut($currency, $amount, $phone, $message, $externalId, $version = null) {
        self::checkInitialization();        
        $resp = [
            'payOut' => false,
            'message' => '',
            'call' => null
        ];
        $referenceId = self::GUUID4();
        $url1 = self::$apiUrl[self::$env] . 'apiuser';
        $headers1 = self::$headers;
        $headers1[] = "X-Reference-Id: $referenceId";
        $headers1[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $data1 = json_encode(["providerCallbackHost" => self::$callback]);
        $call1 = self::CURLRequest($url1, $headers1, $data1);
        if($call1['Code'] === 201) {
            $url2 = self::$apiUrl[self::$env] . 'apiuser/' . $referenceId;
            $headers2 = self::$headers;
            $headers2[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
            $call2 = self::CURLRequest($url2, $headers2);
            if ($call2['Code'] === 200) {
                $url3 = self::$apiUrl[self::$env] . 'apiuser/' . $referenceId . '/apikey';
                $headers3 = self::$headers;
                $headers3[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                $call3 = self::CURLRequest($url3, $headers3, json_encode([]));
                if ($call3['Code'] === 201) {
                    $apiKey = $call3['Details']->apiKey;
                    $url4 = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'disbursement/token/';
                    $headers4 = self::$headers;
                    $headers4[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                    $basicAuth = $referenceId . ':' . $apiKey;
                    $call4 = self::CURLRequest($url4, $headers4, json_encode([]), $basicAuth);
                    if ($call4['Code'] === 200) {
                        $accessToken = $call4['Details']->access_token;
                        $url5 = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'disbursement/v1_0/transfer';
                        $headers5 = self::$headers;
                        $headers5[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                        $headers5[] = "X-Reference-Id: $referenceId";
                        $headers5[] = "X-Target-Environment: " . strtolower(self::$env);
                        $headers5[] = "Authorization: Bearer $accessToken";
                        $data5 = json_encode([
                            "amount" => $amount,
                            "currency" => $currency,
                            "externalId" => $externalId,
                            "payee" => [
                                "partyIdType" => "MSISDN",
                                "partyId" => $phone
                            ],
                            "payerMessage" => $message['message'],
                            "payeeNote" => $message['note']
                        ]);
                        $call5 = self::CURLRequest($url5, $headers5, $data5);
                        if ($call5['Code'] === 202) {
                            $resp['payOut'] = true;
                            $resp['message'] = 'Request Sent';
                            $resp['referenceId'] = $referenceId;
                            $resp['apiKey'] = $apiKey;
                            $resp['response'] = $call5['Details'];
                        } else {
                            $resp['message'] = 'ERROR #T0005';
                            $resp['response'] = $call5;
                        }
                    } else {
                        $resp['message'] = 'ERROR #T0004';
                        $resp['response'] = $call4;
                    }
                } else {
                    $resp['message'] = 'ERROR #T0003';
                    $resp['response'] = $call3;
                }
            } else {
                $resp['message'] = 'ERROR #T0002';
                $resp['response'] = $call2;
            }
        } else {
            $resp['message'] = 'ERROR #T0001';
            $resp['response'] = $call1;
        }
        return $resp;
    }    
    public static function requestToPayStatus($type, $referenceId, $apiKey) {
        self::checkInitialization();        
        $resp = [
            'checkTransactionStatus' => false,
            'message' => '',
            'response' => null
        ];
        $validTypes = ['collection', 'disbursement'];
        if (!in_array($type, $validTypes)) {
            $resp['message'] = 'Invalid transaction type';
            return $resp;
        }
        // Step 1 - Fetch access token
        $tokenUrl = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . "{$type}/token/";
        $headers1 = self::$headers;
        $headers1[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $basicAuth = $referenceId . ':' . $apiKey;
        $call1 = self::CURLRequest($tokenUrl, $headers1, json_encode([]), $basicAuth);
        if ($call1['Code'] !== 200) {
            $resp['message'] = 'ERROR #CTS0001';
            $resp['response'] = $call1;
            return $resp;
        }
        $accessToken = $call1['Details']->access_token;
        // Step 2 - Check status
        $endpoint = $type === 'disbursement' ? 'transfer' : 'requesttopay';
        $statusUrl = str_ireplace('v1_0/', '', self::$apiUrl[self::$env])
            . "{$type}/v1_0/{$endpoint}/{$referenceId}";
        $headers2 = self::$headers;
        $headers2[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $headers2[] = "X-Target-Environment: " . strtolower(self::$env);
        $headers2[] = "Authorization: Bearer {$accessToken}";
        $call2 = self::CURLRequest($statusUrl, $headers2);

        $resp['checkTransactionStatus'] = true;
        $resp['message'] = 'Status Fetched';
        $resp['response'] = $call2['Details'];
        return $resp;
    }
    
    public static function getBalance($type) {
        self::checkInitialization();        
        $resp = [
            'getBalance' => false,
            'message' => '',
            'response' => null
        ];
        // Validate type
        $validTypes = ['collection', 'disbursement'];
        if (!in_array($type, $validTypes)) {
            $resp['message'] = 'Invalid account balance type';
            return $resp;
        }
        // Step 1 - Create API user
        $referenceId = self::GUUID4();
        $url1 = self::$apiUrl[self::$env] . 'apiuser';
        $headers1 = self::$headers;
        $headers1[] = "X-Reference-Id: {$referenceId}";
        $headers1[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $payload1 = json_encode(['providerCallbackHost' => self::$callback]);
        $call1 = self::CURLRequest($url1, $headers1, $payload1);
        if ($call1['Code'] !== 201) {
            $resp['message'] = 'ERROR #AC0001: User not created';
            $resp['response'] = $call1;
            return $resp;
        }

        // Step 2 - Fetch API key
        $url2 = self::$apiUrl[self::$env] . 'apiuser/' . $referenceId . '/apikey';
        $headers2 = self::$headers;
        $headers2[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $call2 = self::CURLRequest($url2, $headers2, json_encode([]));
        if ($call2['Code'] !== 201) {
            $resp['message'] = 'ERROR #AC0003: API key not retrieved';
            $resp['response'] = $call2;
            return $resp;
        }
        $apiKey = $call2['Details']->apiKey;
        // Step 3 - Get access token
        $tokenUrl = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . "{$type}/token/";
        $headers3 = self::$headers;
        $headers3[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $basicAuth = $referenceId . ':' . $apiKey;
        $call3 = self::CURLRequest($tokenUrl, $headers3, json_encode([]), $basicAuth);
        if ($call3['Code'] !== 200) {
            $resp['message'] = 'ERROR #AC0004: Access token not retrieved';
            $resp['response'] = $call3;
            return $resp;
        }
        $accessToken = $call3['Details']->access_token;
        $balanceUrl = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]). "{$type}/v1_0/account/balance";
        $headers4 = self::$headers;
        $headers4[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
        $headers4[] = "X-Target-Environment: " . strtolower(self::$env);
        $headers4[] = "Authorization: Bearer {$accessToken}";
        $call4 = self::CURLRequest($balanceUrl, $headers4);
        if ($call4['Code'] !== 200) {
            $resp['message'] = 'ERROR #AC0005: Balance not retrieved';
            $resp['response'] = $call4;
            return $resp;
        }
        $resp['getBalance'] = true;
        $resp['message'] = ucwords($type) . ' Balance fetched';
        $resp['response'] = $call4['Details'];
        return $resp;
    } 
	
    public static function getKyc($phone, $query) {
        self::checkInitialization();        
        $resp = [];
        if (in_array($query, ['basicuserinfo', 'active'])) {
            //Step1 - Create user
            $referenceId = self::GUUID4();
            $url1 = self::$apiUrl[self::$env] . 'apiuser';
            $requestHeaders1 = self::$headers;
            $requestHeaders1[] = "X-Reference-Id: " . $referenceId;
            $requestHeaders1[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
            $requestData1 = json_encode([
                "providerCallbackHost" => self::$callback
            ]);
            $call1 = self::CURLRequest($url1, $requestHeaders1, $requestData1);
            if ($call1['Code'] === 201){                
                // Step 2 - Get user
                $url2 = self::$apiUrl[self::$env] . 'apiuser/' . $referenceId;
                $requestHeaders2 = self::$headers;
                $requestHeaders2[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                $call2 = self::CURLRequest($url2, $requestHeaders2);
                if ($call2['Code'] === 200) {
                    // Step3 Get ApiKey
                    $url3 = self::$apiUrl[self::$env] . 'apiuser/' . trim($referenceId) . '/apikey';
                    $requestHeaders3 = self::$headers;
                    $requestHeaders3[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                    //Pass Empty data request
                    $call3 = self::CURLRequest($url3, $requestHeaders3, json_encode([]));
                    if ($call3['Code'] === 201) {
                        $apiKey = $call3['Details']->apiKey;                        
                        //Step4 - Get Access Token
                        $url4 = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'collection/token/';
                        $requestHeaders4 = self::$headers;
                        $requestHeaders4[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                        $basicAuth = $referenceId . ':' . $apiKey;
                        $call4 = self::CURLRequest($url4, $requestHeaders4, json_encode([]), $basicAuth);
                        if ($call4['Code'] === 200) {
                            $accessToken = $call4['Details']->access_token;                            
                            //Step5 - Check Account Holder
                            $url = str_ireplace('v1_0/', '', self::$apiUrl[self::$env]) . 'collection/v1_0/accountholder/msisdn/' . $phone . '/' . $query;
                            $requestHeaders5 = self::$headers;
                            $requestHeaders5[] = "Ocp-Apim-Subscription-Key: " . self::$primaryKey;
                            $requestHeaders5[] = "X-Target-Environment: " . strtolower(self::$env);
                            $requestHeaders5[] = "Authorization: Bearer " . $accessToken;
                            $call5 = self::CURLRequest($url, $requestHeaders5);
							$resp['getKyc'] = true;
                            $resp['message'] = 'KYC details';
                            $resp['response'] = $call5['Details'];
                        } else {
                            $resp['getKyc'] = false;
                            $resp['message1'] = 'Access Token Not Gotten';
                            $resp['message'] = 'ERROR #AH0004';
                            $resp['response'] = $call4;
                        }
                    } else {
                        $resp['getKyc'] = false;
                        $resp['message1'] = 'Api Key Not Gotten';
                        $resp['message'] = 'ERROR #AH0003';
                        $resp['response'] = $call3;
                    }
                } else {
                    $resp['getKyc'] = false;
                    $resp['message1'] = 'User Not Gotten';
                    $resp['message'] = 'ERROR #AH0002';
                    $resp['response'] = $call2;
                }
            } else {
                $resp['getKyc'] = false;
                $resp['message1'] = 'User Not Created';
                $resp['message'] = 'ERROR #AH0001';
                $resp['response'] = $call1;
            }
        } else {
            $resp['getKyc'] = false;
            $resp['message1'] = 'User query not known';
            $resp['message'] = 'ERROR wrong query';
            $resp['response'] = [];
        }
        return $resp;
    }
    
    private static function GUUID4($data = null) {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }    
    private static function CURLRequest($url, $headers = NULL, $data = NULL, $login = NULL) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        if (!empty($login)) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $login);
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'CissyTech');
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        if ($response) {
            $resp['Error'] = false;
            $resp['Details'] = json_decode($response);
        } else {
            $resp['Details'] = false;
            if (curl_error($ch)) {
                $resp['Error'] = curl_error($ch);
            }
        }
        $resp['Code'] = $info['http_code'];
        curl_close($ch);
        return $resp;
    }
}
// Initialize the class
MOMOPAYAPI::init(
    'c33022b7e5f244259979d9a66f218583',
    '197f8431176946dba9fb50adbc063d1e', 
    'http://momopay.cissytech.com/php/callback.php',
    'Sandbox'
);

// Use the methods
/*$result = MOMOPAYAPI::requestToPay(
    'EUR',
    1000,
    '46733123464',
    ['message' => 'Payment for services', 'note' => 'Thank you'],
    'ext123'
);
*/

$result = MOMOPAYAPI::getKyc(46733123464, 'basicuserinfo');
//$result = MOMOPAYAPI::getBalance('collection');

echo json_encode($result);

?>