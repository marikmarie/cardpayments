<?

	$logPath1 = 'logs/';
	$data1 = json_decode(file_get_contents('php://input'), true);
	$logFile = 'momo.txt';
	file_put_contents($logPath1.$logFile, json_encode(array('Time'=>date('h:i:s a'),'Data'=>$data1)));
?>