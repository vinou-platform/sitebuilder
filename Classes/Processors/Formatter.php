<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;

class Formatter {

	public $data = [];

	public function __construct() {

	}

	public function mergeData($data) {
		$returnArr = [];
		foreach ($data as $key => $subdata) {
			switch (gettype($subdata)) {

				case 'array':
					if (array_key_exists('data', $subdata))
						$subdata = $subdata['data'];

					foreach ($subdata as $entry) {
						if (is_array($entry) && array_key_exists('data', $entry))
							$entry = $entry['data'];

						if (empty($entry) || is_null($entry))
							continue;

						if (is_string($key) && is_array($entry))
							$entry['object_type'] = $key;

						$returnArr[] = $entry;

					}
					break;

				case 'string':
					$returnArr[] = $subdata;
					break;

				default:
					break;
			}
		}

		return $returnArr;
	}

}
?>