<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;
use \GuzzleHttp\Client;
use \GuzzleHttp\Psr7;
use \GuzzleHttp\Psr7\Request;
use \GuzzleHttp\Exception\ClientException;
use \GuzzleHttp\Exception\RequestException;
use \GuzzleHttp\Cookie\CookieJar;
use \Monolog\Logger;
use \Monolog\Handler\RotatingFileHandler;

class Instagram {

	public $data = [];
	public $cacheDir = 'Cache/Instagram';

	/**
	 * @var boolean $enableLogging enable logging into log array
	 */
	public $enableLogging;

	/**
	 * @var array $log array to log processes
	 */
	public $log = [];

	/**
	 * @var object $httpClient guzzle object for client
	 */
	public $httpClient = null;

	/**
	 * @var boolean $crawlerDetect shows if a crawler was detected
	 */
	public $crawler = false;

	/**
	 * @var object $logger monolog object for logs
	 */
	public $logger = null;

	/**
	 * @var object $cookieJar cookieJar from guzzle
	 */
	public $cookieJar = null;


	public function __construct() {
		$this->initCacheDir();
		$this->httpClient = new Client([
			'cookies' => true,
			'base_uri' => 'https://www.instagram.com/'
		]);

		$this->initLogging();
	}

	private function initCacheDir() {
		$this->cacheDir = Helper::getNormDocRoot().$this->cacheDir;
		if (!is_dir($this->cacheDir))
			mkdir($this->cacheDir, 0777, true);
	}

	private function initLogging() {

		$logDirName = defined('VINOU_LOG_DIR') ? VINOU_LOG_DIR : 'logs/';

		$logDir = Helper::getNormDocRoot() . $logDirName;

        if (!is_dir($logDir))
            mkdir($logDir, 0777, true);

        $htaccess = $logDir .'/.htaccess';
        if (!is_file($htaccess)) {
            $content = 'Deny from all';
            file_put_contents($htaccess, $content);
        }

		$loglevel = defined('VINOU_LOG_LEVEL') ? Logger::VINOU_LOG_LEVEL : Logger::ERROR;

		if (defined('VINOU_DEBUG') && VINOU_DEBUG)
			$loglevel = Logger::DEBUG;

		$this->logger = new Logger('instagram');
		$this->logger->pushHandler(new RotatingFileHandler($logDir.'instagram.log', 30, $loglevel));
	}

	private function simulateCookies($sessionid = null) {
		if ($sessionid || defined('IG_SESSION')) {
			$id = defined('IG_SESSION') ? IG_SESSION : $sessionid;
			$this->cookieJar = CookieJar::fromArray([
	    		'sessionid' => $id
			], 'instagram.com');
		}
	}

	private function curlInstagramAPI($data = []) {

		$headers = [
			'User-Agent' => 'vinou-sitebuilder',
			'Content-Type' => 'application/json',
			'Origin' => ''.$_SERVER['SERVER_NAME']
		];


		try {
			$response = $this->httpClient->request(
				'POST',
				'graphql/query/',
				[
			    	'headers' => $headers,
			    	'cookies' => $this->cookieJar,
			    	'json' => $data
				]
			);

			// insert status and response from successful request to logdata and logdata on dev devices
			$logData = [
				'Status' => 200,
				'Response' => json_decode((string)$response->getBody(), true)
			];
			$this->logger->debug('api request', $logData);

			$result = json_decode((string)$response->getBody(), true);
			return isset($result['data']) && !is_null($result['data']) ? $result['data'] : false;

		} catch (ClientException $e) {

			$statusCode = $e->getResponse()->getStatusCode();

			// insert status and response from error request
			$logData = [
				'ROUTE' => 'graphql/query/',
				'Status' => $statusCode,
				'Response' => json_decode((string)$e->getResponse()->getBody(), true)
			];

			switch ($statusCode) {

				case '401':
					// if only authorization is missing the error is only a warning
					$this->logger->warning('unauthorized', $logData);
					break;

				case '429':
					// if only authorization is missing the error is only a warning
					$this->logger->error('too many requests', $logData);
					break;

				default:
					// all other errors should be fixed immediatly
					$this->logger->error('error', $logData);
					break;

			}

			return false;
		}
	}

	public function loadTimelinePosts($postData = false, $force = false) {
		if (defined('IG_USER'))
			$postData['id'] = IG_USER;

		if (!array_key_exists('id', $postData))
			return false;

		if (!array_key_exists('first', $postData))
			$postData['first'] = 10;

		if (array_key_exists('sessionid', $postData)) {
			$this->simulateCookies($postData['sessionid']);
			unset($postData['sessionid']);
		}

		$cacheFile = $this->cacheDir . '/' . $postData['id'] . '.json';
		$postData = [
			'query_hash' => '56a7068fea504063273cc2120ffd54f3',
			'variables' => $postData
		];

		if (!file_exists($cacheFile) || time() - filemtime($cacheFile) > 900 || $force ) {

			$data = $this->curlInstagramAPI($postData);
			if (!is_array($data) || !array_key_exists('user',$data))
				return false;

			$data = $data['user'];

			if (array_key_exists('edge_owner_to_timeline_media', $data)) {
				$result = [];
				$rawNodes = $data['edge_owner_to_timeline_media']['edges'];
				foreach ($rawNodes as $key => $node) {
					array_push($result, $node['node']);
				}
				file_put_contents($cacheFile,json_encode($result));
			}

			if (!file_exists($cacheFile))
				return false;
		}
		else
			$result = json_decode(file_get_contents($cacheFile), true);

		return $result;
	}

}