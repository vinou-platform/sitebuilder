<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;

class External {

	public $data = [];

	public function __construct() {

	}

	public function loadURL($params) {
		if (!isset($params['url']))
			return false;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $params['url']);
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

	public function loadFile($file, $type) {
		if (substr($file,0,1) != '/')
			$file = Helper::getNormDocRoot() . $file;

		if (!is_file($file))
			return 'file not found';

		switch(strtolower($type)) {
			case 'json':
				$content = file_get_contents($file);
				return json_decode($content, true);
				break;

			default:
				break;
		}

		return false;
	}

	public function loadJSONFile($params) {
		if (!isset($params['file']))
			return false;

		return $this->loadFile($params['file'], 'json');
	}

}
?>