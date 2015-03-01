<?php
	/* This is a new approach to Julian's solution for webapps running on Google App Engine.
	   Only recently (2015-02-26) Google's PHP App Engine Team announced cURL extension 
	   would be available. What they have failed to mention is that the option CAINFO
	   would not be supported by their cURL-light implementation.
	   Thus the best solution would be to use AppEngine's urlfetch, which also allows 
	   best performance than cURL while running in such environment.
	*/

	//phpinfo();
	//var_dump($_SERVER);
	session_start(); //$_SESSION['appInUse'] = true; 
	
	class urlfetch_sabre{
		protected $_url = "https://api.test.sabre.com/";
		//Go to https://developer.sabre.com/docs/read/rest_basics/authentication
		//to Read more on building your APP Key.
		protected $_dsAppKey = "V1:7sxucb6bpftqnc82:DEVCENTER:EXT";
		protected $_dsSecret = "SfBu9Gu3";
		protected $_expireAt = null;
		protected $_debugMode = false;
		protected $_numretries = 0;
		
		private function checkExpDate() {
			$dtToken = $_SESSION['expireAt']; //expireAt stored as unix timestamp
			$dtNow = time();
			$subTime = $dtToken - $dtNow;
			
			if ($subTime > 0) return true;
			else return false;
		}
		
		private function getAuthToken() {
				$dsAppKey = base64_encode(
					base64_encode($this->_dsAppKey) . ":" .
					base64_encode($this->_dsSecret)
				);

				$headers = "Content-type: application/x-www-form-urlencoded; charset=UTF-8\r\n" .
					"Authorization: Basic " . $dsAppKey . "\r\n";
				$context = [
					'http' => [
						'method' => 'POST',
						'header' => $headers,
						'content' => 'grant_type=client_credentials'
					],
					'ssl' => [
						'cafile' => dirname(__FILE__) . '/cacerts.pem'
					] 
				];
				$context = stream_context_create($context);
				$res = file_get_contents($this->_url . "v1/auth/token", false, $context);
				$res = json_decode($res, true);

				$_SESSION['lastToken'] = $res['access_token'];
				$_SESSION['initAt'] = time();
				$_SESSION['expireAt'] = time() + $res['expires_in'];
		}
		
		private function sendRequest($payload = '') {
			$res = null;
			if (!isset($_SESSION['lastToken']) || !$this->checkExpDate()) { $this->getAuthToken(); }
			
			if (isset($_SESSION['lastToken'])) {
				$context = [
					'http' => [
						'method' => 'GET',
						'header' => 'Authorization: Bearer ' . $_SESSION['lastToken'] . '',
					],
					'ssl' => [
						'cafile' => dirname(__FILE__) . '/cacerts.pem'
					] 
				];
				$context = stream_context_create($context);
				$res = file_get_contents($this->_url . $payload, false, $context);
				//$res = json_decode($res);
				
				if ($http_response_header[0] != "HTTP/1.1 200 OK" && $this->_numretries == 0) { //there was an error => retry once
					$this->_numretries++;
					return $this->sendRequest($payload);
				}
			}
			$_SESSION['lastInfo'] = $res;
			$_SESSION['lastInfo-header'] = $http_response_header;
			return $res;
		}
		
		public function sendResponse($status = 'HTTP/1.1 200 ', $body = '', $content_type = 'text/html') {
			header($status);
			header('Content-type: ' . $content_type);
			header('Access-Control-Allow-Origin: *');

			if($body != '') echo $body;
			else echo "";
		}
		
		public function handleRequest($_SRV, $G, $P) {
			$location = $_SRV['REQUEST_URI'];
			$qs = $_SRV['QUERY_STRING'];
			$res = null;
			$this->_numretries = 0;

			if ($qs) {
				$location = substr($location, 0, strpos($location,$qs)-1);
				if (isset($G['debug'])) { $this->_debugMode = true; }
			}

			if(isset($_SESSION['appInUse']) == false){
				$this->sendResponse($status = 'HTTP/1.1 401', $body = 'forbidden by access control');
			}
			
			if($location && strpos($location, "v1/", 0) >=0 ) {
				$location = substr($location, strpos($location, "v1/",0));
				$res = $this->sendRequest($payload = $location . (strlen($qs) > 0 ? '?'.$qs : ''));
			}
			
			$st = $_SESSION['lastInfo-header'][0];//$this->_lastInfo['http_code'];
			$ct = $_SESSION['lastInfo-header'][3];//$this->_lastInfo['content_type'];

			if (!is_null($res) && strpos($st, '200') !== false) {
				$this->sendResponse($status = $st, $body = $res, $content_type = $ct);
			} elseif (strpos($st, '401') > 0) {
				unset($_SESSION['lastToken']);
				unset($_SESSION['expireAt']);
				unset($_SESSION['initAt']);
				$this->sendResponse($status = $st, $body = $res);
			} else {
				$this->sendResponse($status = $st, $body = $res);
			}
		}
	}

	$db = new urlfetch_sabre();
	$db->handleRequest($_SERVER, $_GET, $_POST);
	//var_dump($_SESSION);
?>
