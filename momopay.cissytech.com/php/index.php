<?
	header("Content-type:application/json");
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS, PATCH, DELETE');
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Headers: Authorization, Content-Type, x-xsrf-token, x_csrftoken, Cache-Control, X-Requested-With');
	
	require('momopay_api_php_class.php');
	
	$momo = new MOMOPAYAPI();
	$resp = [];
        if(isset($_GET['a'])){
        	$requestData = json_decode(file_get_contents('php://input'), true);
        	if($requestData){
        		foreach($requestData as $k=>$v){
					$requestData[$k] = $v;
				}
        	}
        	if($_GET['a']==='RequestToPay'){
				try {
					// Required keys
					$requiredKeys = ['currency', 'amount', 'phone', 'message', 'externalId'];			
					// Validate each key
					foreach ($requiredKeys as $key) {
						if (!isset($requestData[$key])) {
							throw new Exception("Missing required field: $key");
						}
					}			    
					//$resp = [true,"All required fields are set."];
					$resp = $momo->RequestToPay('Sandbox',$requestData['currency'],$requestData['amount'],$requestData['phone'],$requestData['message'],$requestData['externalId']);			
				} catch (Exception $e) {
					$resp = [false,"Error: " . $e->getMessage()];
				}        		
        	}
			if($_GET['a']==='Transfer'){
				try {
					// Required keys
					$requiredKeys = ['currency', 'amount', 'phone', 'message', 'externalId'];			
					// Validate each key
					foreach ($requiredKeys as $key) {
						if (!isset($requestData[$key])) {
							throw new Exception("Missing required field: $key");
						}
					}			    
					//$resp = [true,"All required fields are set."];
					$resp = $momo->Transfer('Sandbox',$requestData['currency'],$requestData['amount'],$requestData['phone'],$requestData['message'],$requestData['externalId']);			
				} catch (Exception $e) {
					$resp = [false,"Error: " . $e->getMessage()];
				}        		
        	}
			elseif($_GET['a']==='TransactionStatus'){
				try {
					// Required keys
					$requiredKeys = ['type','referenceId','apiKey'];			
					// Validate each key
					foreach ($requiredKeys as $key) {
						if (!isset($requestData[$key])) {
							throw new Exception("Missing required field: $key");
						}
					}			    
					//$resp = [true,"All required fields are set."];
					$resp = $momo->CheckTransactionStatus('Sandbox',$requestData['type'],$requestData['referenceId'],$requestData['apiKey']);			
				} catch (Exception $e) {
					$resp = [false,"Error: " . $e->getMessage()];
				}
			}
			elseif($_GET['a']==='AccountBalance'){
				try {
					// Required keys
					$requiredKeys = ['type'];			
					// Validate each key
					foreach ($requiredKeys as $key) {
						if (!isset($requestData[$key])) {
							throw new Exception("Missing required field: $key");
						}
					}			    
					//$resp = [true,"All required fields are set."];
					$resp = $momo->AccountBalance('Sandbox',$requestData['type']);			
				} catch (Exception $e) {
					$resp = [false,"Error: " . $e->getMessage()];
				}
			}
			elseif($_GET['a']==='AccountHolder'){
				try {
					// Required keys
					$requiredKeys = ['phone','query'];			
					// Validate each key
					foreach ($requiredKeys as $key) {
						if (!isset($requestData[$key])) {
							throw new Exception("Missing required field: $key");
						}
					}			    
					//$resp = [true,"All required fields are set."];
					$resp = $momo->AccountHolder('Sandbox',$requestData['phone'],$requestData['query']);			
				} catch (Exception $e) {
					$resp = [false,"Error: " . $e->getMessage()];
				}
			}
        }
        echo json_encode($resp);
?>