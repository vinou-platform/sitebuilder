<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;

class Instagram {

	public $data = [];
	public $cacheDir = 'Cache/Instagram';

	public function __construct() {
		$this->initCacheDir();
	}

	private function initCacheDir() {
		$this->cacheDir = Helper::getNormDocRoot().$this->cacheDir;
		if (!is_dir($this->cacheDir))
			mkdir($this->cacheDir, 0777, true);
	}

	private function curlURL($url) {

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		$result = curl_exec($ch);
		$requestinfo = curl_getinfo($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		switch ($httpCode) {
			case 200:
				curl_close($ch);
				return $result;
				break;
			case 401:
				return [
					'error' => 'unauthorized',
					'info' => $requestinfo,
					'response' => $result
				];
				break;
			default:
				return [
					'error' => 'an error occured',
					'info' => $requestinfo,
					'response' => $result
				];
				break;
		}
		return false;
	}

	public function loadPosts($params = false) {
		$username = false;

		if (defined('IG_USER'))
			$username = IG_USER;

		if (is_array($params) && array_key_exists('username', $params))
			$username = $params['username'];

		if (!$username)
			return false;

		$url = 'https://instagram.api.kartoffel-server.com/api/posts/' . urlencode($username);
		$cacheFile = $this->cacheDir . '/' . $username . '.json';

		if (!file_exists($cacheFile) || time() - filemtime($cacheFile) > 900 ) {
			$result = $this->curlURL($url);
			file_put_contents($cacheFile,$result);
		}
		else {
			$result = file_get_contents($cacheFile);
		}

		return json_decode($result, true);
	}
}