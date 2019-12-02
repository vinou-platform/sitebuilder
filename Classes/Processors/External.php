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

		return file_get_contents($params['url']);
	}

}
?>