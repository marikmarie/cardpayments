<?php 

	Class MOMOPAYAPI {
		
		private
			$headers = array(
				'Content-Type: application/json',
				'Accept: application/json'
			),
			$primaryKeys = array(
				"Sandbox" => array(
					"collection" => "c33022b7e5f244259979d9a66f218583",
					"disbursement" => "3d648cde09564eccba226f60e155969f"
				),
				"Live" => array()
			),
			$secondaryKeys = array(
				"Sandbox" => array(
					"collection" => "197f8431176946dba9fb50adbc063d1e",
					"disbursement" => "7f94758012ca4c229e4db3a3e7f36796"
				),
				"Live"	=> array()
			),			
			$apiUrl = array(
				"Sandbox" => "https://sandbox.momodeveloper.mtn.com/v1_0/",
				"Live" => ""
			),
			$callback = 'http://momopay.cissytech.com/php/callback.php';
		
		
		public function RequestToPay($env,$currency,$amount,$phone,$message,$externalId,$version=null){
			
			$resp = [];
			//Step1 - Create user
			$referenceId = $this->GUUID4(); //Save it in a database or file for future reference
			$url1 = $this->apiUrl[$env].'apiuser';
			$requestHeaders1 = $this->headers;
			$requestHeaders1[] = "X-Reference-Id: ".$referenceId;
			$requestHeaders1[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];
			$requestData1 = json_encode(array(
				"providerCallbackHost" => $this->callback
			));
			
			$call1 = $this->CURLRequest($url1,$requestHeaders1,$requestData1);
			if($call1['Code']===201){
				
				// Step 2 - Get user
				$url2 = $this->apiUrl[$env].'apiuser/'.$referenceId;
				$requestHeaders2 = $this->headers;
				$requestHeaders2[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];	
				$call2 = $this->CURLRequest($url2,$requestHeaders2);
				if($call2['Code']===200){
					
					// Step3 Get ApiKey
					$url3 = $this->apiUrl[$env].'apiuser/'.trim($referenceId).'/apikey';
					$requestHeaders3 = $this->headers;
					$requestHeaders3[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];	
					//Pass Empty data request
					$call3 = $this->CURLRequest($url3,$requestHeaders3,json_encode([]));
					if($call3['Code']===201){
						$apiKey = $call3['Details']->apiKey; //Save it also in a database or file for future reference
						
						//Step4 - Get Access Token
						$url4 = str_ireplace('v1_0/','',$this->apiUrl[$env]).'collection/token/';
						$requestHeaders4 = $this->headers;
						$requestHeaders4[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];
						$basicAuth = $referenceId.':'.$apiKey;
						$call4 = $this->CURLRequest($url4,$requestHeaders4,json_encode([]),$basicAuth);
						if($call4['Code']===200){
							$accessToken = $call4['Details']->access_token;
							
							//Step5 - RequestPay
							if($version){
								if($version==2){
									$url5 = str_ireplace('v1_0/','',$this->apiUrl[$env]).'collection/v2_0/requesttowithdraw';
								}
							}else{
								$url5 = str_ireplace('v1_0/','',$this->apiUrl[$env]).'collection/v1_0/requesttopay';
							}
							$requestHeaders5 = $this->headers;
							$requestHeaders5[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];
							$requestHeaders5[] = "X-Reference-Id: ".$referenceId;
							$requestHeaders5[] = "X-Target-Environment: ".strtolower($env);
							$requestHeaders5[] = "Authorization: Bearer ".$accessToken;
							$requestData5 = json_encode(array(
								"amount" => $amount,
								"currency" => $currency,
								"externalId" => $externalId,
								"payer" => array(
									"partyIdType" => "MSISDN",
									"partyId" => $phone
								),
								"payerMessage" => $message['message'],
								"payeeNote" => $message['note']
							));	
							$call5 = $this->CURLRequest($url5,$requestHeaders5,$requestData5);
							if($call5['Code']===202){
								$resp['requestToPay'] = true;
								$resp['referenceId']= $referenceId;
								$resp['apiKey']= $apiKey;
								$resp['call5'] = $call5;
							}else{
								$resp['requestToPay'] = false;
								$resp['message1'] = 'Request Not Sent';
								$resp['message'] = 'ERROR #R2P0005';
								$resp['call'] = $call5;
							}
							
						}else{
							$resp['requestToPay'] = false;
							$resp['message1'] = 'Access Token Not Gotten';
							$resp['message'] = 'ERROR #R2P0004';
							$resp['call'] = $call4;
						}
					}else{
						$resp['requestToPay'] = false;
						$resp['message1'] = 'ApiKey Not Gotten';
						$resp['message'] = 'ERROR #R2P0003';
						$resp['call'] = $call3;
					}
					
				}else{
					$resp['requestToPay'] = false;
					$resp['message1'] = 'User Not Gotten';
					$resp['message'] = 'ERROR #R2P0002';
					$resp['call'] = $call2;
				}
				
			}else{
				$resp['requestToPay'] = false;
				$resp['message1'] = 'User Not Created';
				$resp['message'] = 'ERROR #R2P0001';
				$resp['call'] = $call1;
			}
			
			return $resp;			
		}

		public function Transfer($env, $currency, $amount, $phone, $message, $externalId, $version = null) {
			$resp = [
				'transfer' => false,
				'message' => '',
				'call' => null
			];

			$referenceId = $this->GUUID4();
			$url1 = $this->apiUrl[$env] . 'apiuser';
			$headers1 = $this->headers;
			$headers1[] = "X-Reference-Id: $referenceId";
			$headers1[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env]['disbursement'];
			$data1 = json_encode(["providerCallbackHost" => $this->callback]);
			$call1 = $this->CURLRequest($url1, $headers1, $data1);

			if ($call1['Code'] === 201) {
				$url2 = $this->apiUrl[$env] . 'apiuser/' . $referenceId;
				$headers2 = $this->headers;
				$headers2[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env]['disbursement'];
				$call2 = $this->CURLRequest($url2, $headers2);

				if ($call2['Code'] === 200) {
					$url3 = $this->apiUrl[$env] . 'apiuser/' . $referenceId . '/apikey';
					$headers3 = $this->headers;
					$headers3[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env]['disbursement'];
					$call3 = $this->CURLRequest($url3, $headers3, json_encode([]));

					if ($call3['Code'] === 201) {
						$apiKey = $call3['Details']->apiKey;

						$url4 = str_ireplace('v1_0/', '', $this->apiUrl[$env]) . 'disbursement/token/';
						$headers4 = $this->headers;
						$headers4[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env]['disbursement'];
						$basicAuth = $referenceId . ':' . $apiKey;
						$call4 = $this->CURLRequest($url4, $headers4, json_encode([]), $basicAuth);

						if ($call4['Code'] === 200) {
							$accessToken = $call4['Details']->access_token;

							$url5 = str_ireplace('v1_0/', '', $this->apiUrl[$env]) . 'disbursement/v1_0/transfer';
							$headers5 = $this->headers;
							$headers5[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env]['disbursement'];
							$headers5[] = "X-Reference-Id: $referenceId";
							$headers5[] = "X-Target-Environment: " . strtolower($env);
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

							$call5 = $this->CURLRequest($url5, $headers5, $data5);

							if ($call5['Code'] === 202) {
								$resp['transfer'] = true;
								$resp['message'] = 'Request Sent';
								$resp['referenceId'] = $referenceId;
								$resp['apiKey'] = $apiKey;
								$resp['call'] = $call5;
							} else {
								$resp['message'] = 'ERROR #T0005';
								$resp['call'] = $call5;
							}
						} else {
							$resp['message'] = 'ERROR #T0004';
							$resp['call'] = $call4;
						}
					} else {
						$resp['message'] = 'ERROR #T0003';
						$resp['call'] = $call3;
					}
				} else {
					$resp['message'] = 'ERROR #T0002';
					$resp['call'] = $call2;
				}
			} else {
				$resp['message'] = 'ERROR #T0001';
				$resp['call'] = $call1;
			}
			return $resp;
		}
		
		public function CheckTransactionStatus($env, $type, $referenceId, $apiKey) {
			$resp = [
				'checkTransactionStatus' => false,
				'message'                => '',
				'call'                   => null
			];

			$validTypes = ['collection', 'disbursement'];
			if (!in_array($type, $validTypes)) {
				$resp['message'] = 'Invalid transaction type';
				return $resp;
			}

			// Step 1 - Fetch access token
			$tokenUrl = str_ireplace('v1_0/', '', $this->apiUrl[$env]) . "{$type}/token/";
			$headers1 = $this->headers;
			$headers1[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env][$type];
			$basicAuth   = $referenceId . ':' . $apiKey;
			$call1       = $this->CURLRequest($tokenUrl, $headers1, json_encode([]), $basicAuth);

			if ($call1['Code'] !== 200) {
				$resp['message'] = 'ERROR #CTS0001';
				$resp['call']    = $call1;
				return $resp;
			}

			$accessToken = $call1['Details']->access_token;

			// Step 2 - Check status
			$endpoint = $type === 'disbursement' ? 'transfer' : 'requesttopay';
			$statusUrl = str_ireplace('v1_0/', '', $this->apiUrl[$env])
				. "{$type}/v1_0/{$endpoint}/{$referenceId}";
			$headers2 = $this->headers;
			$headers2[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env][$type];
			$headers2[] = "X-Target-Environment: " . strtolower($env);
			$headers2[] = "Authorization: Bearer {$accessToken}";
			$call2 = $this->CURLRequest($statusUrl, $headers2);

			$resp['checkTransactionStatus'] = true;
			$resp['message']                = 'Status Fetched';
			$resp['call']                   = $call2;
			return $resp;
		}
		
		public function AccountBalance($env, $type) {
			$resp = [
				'accountBalance' => false,
				'message'        => '',
				'call'           => null
			];

			// Validate type
			$validTypes = ['collection', 'disbursement'];
			if (!in_array($type, $validTypes)) {
				$resp['message'] = 'Invalid account balance type';
				return $resp;
			}

			// Step 1 - Create API user
			$referenceId = $this->GUUID4();
			$url1 = $this->apiUrl[$env] . 'apiuser';
			$headers1 = $this->headers;
			$headers1[] = "X-Reference-Id: {$referenceId}";
			$headers1[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env][$type];
			$payload1 = json_encode(['providerCallbackHost' => $this->callback]);
			$call1 = $this->CURLRequest($url1, $headers1, $payload1);
			if ($call1['Code'] !== 201) {
				$resp['message'] = 'ERROR #AC0001: User not created';
				$resp['call']    = $call1;
				return $resp;
			}

			// Step 2 - Fetch API key
			$url2 = $this->apiUrl[$env] . 'apiuser/' . $referenceId . '/apikey';
			$headers2 = $this->headers;
			$headers2[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env][$type];
			$call2 = $this->CURLRequest($url2, $headers2, json_encode([]));
			if ($call2['Code'] !== 201) {
				$resp['message'] = 'ERROR #AC0003: API key not retrieved';
				$resp['call']    = $call2;
				return $resp;
			}
			$apiKey = $call2['Details']->apiKey;

			// Step 3 - Get access token
			$tokenUrl = str_ireplace('v1_0/', '', $this->apiUrl[$env]) . "{$type}/token/";
			$headers3 = $this->headers;
			$headers3[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env][$type];
			$basicAuth  = $referenceId . ':' . $apiKey;
			$call3      = $this->CURLRequest($tokenUrl, $headers3, json_encode([]), $basicAuth);
			if ($call3['Code'] !== 200) {
				$resp['message'] = 'ERROR #AC0004: Access token not retrieved';
				$resp['call']    = $call3;
				return $resp;
			}
			$accessToken = $call3['Details']->access_token;

			// Step 4 - Check Balance
			$balanceUrl = str_ireplace('v1_0/', '', $this->apiUrl[$env])
				. "{$type}/v1_0/account/balance";
			$headers4 = $this->headers;
			$headers4[] = "Ocp-Apim-Subscription-Key: " . $this->primaryKeys[$env][$type];
			$headers4[] = "X-Target-Environment: " . strtolower($env);
			$headers4[] = "Authorization: Bearer {$accessToken}";
			$call4 = $this->CURLRequest($balanceUrl, $headers4);

			if ($call4['Code'] !== 200) {
				$resp['message'] = 'ERROR #AC0005: Balance not retrieved';
				$resp['call']    = $call4;
				return $resp;
			}

			$resp['accountBalance'] = true;
			$resp['message']        = ucwords($type).' Balance fetched';
			$resp['call']           = $call4;
			return $resp;
		}
		
		function AccountHolder($env,$phone,$query){
			$resp = [];
			if(in_array($query,['basicuserinfo','active'])){
				//Step1 - Create user
				$referenceId = $this->GUUID4(); 
				$url1 = $this->apiUrl[$env].'apiuser';
				$requestHeaders1 = $this->headers;
				$requestHeaders1[] = "X-Reference-Id: ".$referenceId;
				$requestHeaders1[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];
				$requestData1 = json_encode(array(
					"providerCallbackHost" => $this->callback
				));			
				$call1 = $this->CURLRequest($url1,$requestHeaders1,$requestData1);
				if($call1['Code']===201){
					
					// Step 2 - Get user
					$url2 = $this->apiUrl[$env].'apiuser/'.$referenceId;
					$requestHeaders2 = $this->headers;
					$requestHeaders2[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];	
					$call2 = $this->CURLRequest($url2,$requestHeaders2);
					if($call2['Code']===200){					
						// Step3 Get ApiKey
						$url3 = $this->apiUrl[$env].'apiuser/'.trim($referenceId).'/apikey';
						$requestHeaders3 = $this->headers;
						$requestHeaders3[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];	
						//Pass Empty data request
						$call3 = $this->CURLRequest($url3,$requestHeaders3,json_encode([]));
						if($call3['Code']===201){
							$apiKey = $call3['Details']->apiKey;
							
							//Step4 - Get Access Token
							$url4 = str_ireplace('v1_0/','',$this->apiUrl[$env]).'collection/token/';
							$requestHeaders4 = $this->headers;
							$requestHeaders4[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];
							$basicAuth = $referenceId.':'.$apiKey;
							$call4 = $this->CURLRequest($url4,$requestHeaders4,json_encode([]),$basicAuth);
							if($call4['Code']===200){
								$accessToken = $call4['Details']->access_token;
								
								//Step5 - Check Account Holder
								$url = str_ireplace('v1_0/','',$this->apiUrl[$env]).'collection/v1_0/accountholder/msisdn/'.$phone.'/'.$query;
								$requestHeaders5 = $this->headers;
								$requestHeaders5[] = "Ocp-Apim-Subscription-Key: ".$this->primaryKeys[$env]['collection'];
								$requestHeaders5[] = "X-Target-Environment: ".strtolower($env);
								$requestHeaders5[] = "Authorization: Bearer ".$accessToken;
								$call5 = $this->CURLRequest($url,$requestHeaders5);
								$resp['call5'] = $call5;
							}else{
								$resp['accountHolder'] = false;
								$resp['message1'] = 'Access Token Not Gotten';
								$resp['message'] = 'ERROR #AH0004';
								$resp['call'] = $call4;
							}
						}else{
							$resp['accountHolder'] = false;
							$resp['message1'] = 'Api Key Not Gotten';
							$resp['message'] = 'ERROR #AH0003';
							$resp['call'] = $call3;
						}
					}else{
						$resp['accountHolder'] = false;
						$resp['message1'] = 'User Not Gotten';
						$resp['message'] = 'ERROR #AH0002';
						$resp['call'] = $call2;
					}
				}else{
					$resp['accountHolder'] = false;
					$resp['message1'] = 'User Not Created';
					$resp['message'] = 'ERROR #AH0001';
					$resp['call'] = $call1;
				}
			}else{
				$resp['accountHolder'] = false;
				$resp['message1'] = 'User query not known';
				$resp['message'] = 'ERROR wrong query';
				$resp['call'] = [];
			}
			return $resp;
		}
		
		private function GUUID4($data = null) {
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
		
		private function CURLRequest($url,$headers=NULL,$data=NULL,$login=Null){
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			if (!empty($headers)){
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			}
			if(!empty($data)){
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			}
			if(!empty($login)){
				curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
				curl_setopt($ch, CURLOPT_USERPWD, $login);
			}
			curl_setopt($ch, CURLOPT_USERAGENT, 'CissyTech');
			curl_setopt($ch, CURLOPT_TIMEOUT, 0);
			curl_setopt($ch, CURLINFO_HEADER_OUT, true);
			$response = curl_exec($ch);
			$info = curl_getinfo($ch);
			if($response){
			    $resp['Error'] = false;
			    $resp['Details'] = json_decode($response);
			}else{
			    $resp['Details'] = false;
			    if(curl_error($ch)){
			     	$resp['Error'] = curl_error($ch);
			    }
			}
			$resp['Code'] =  $info['http_code']; 
			//curl_getinfo($ch, CURLINFO_HTTP_CODE);
			//$resp['FullRequest']= json_encode(array('URL'=>$url,'Headers'=>$headers,'Data'=>$data,'Info'=>$info));
			curl_close($ch);
			return $resp;
		}
	}
	
	
	/****Testing******/	
	
	$momo  =  new MOMOPAYAPI();
	$externalId = time();
	
	/**** Testing ONE ******/	
	$externalId = time();
	//echo '<pre>',print_r($momo->RequestToPay('Sandbox','EUR','5.0','46733123452',['message'=>'Paying For Goods','note'=>'CissyTech'],$externalId)),'</pre>';
	
	
	/**** Testing TWO ******/
	// Use the referenceId  & apiKey from Testing ONE
	//echo '<pre>',print_r($momo->CheckTransactionStatus('Sandbox','9ecdb222-fcea-4caf-96ae-a6431823c718','4a8b9adc81ca43a99fe3fe9779aac081')),'</pre>';
	
	
	/**** Testing THREE ******/	
	//echo '<pre>',print_r($momo->AccountBalance('Sandbox')),'</pre>';
	
	
	/**** Testing FOUR ******/	
	//basicuserinfo or active
	//echo '<pre>',print_r($momo->AccountHolder('Sandbox','0781776645','basicuserinfo')),'</pre>';
?>