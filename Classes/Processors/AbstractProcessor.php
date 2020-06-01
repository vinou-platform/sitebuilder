<?php
namespace Vinou\SiteBuilder\Processors;

class AbstractProcessor {

	public $api = NULL;

	public function __construct() {

    }

    public function loadApi(&$api = NULL) {

		if (is_null($api))
            throw new \Exception('no api was initialized');
        else
            $this->api = $api;
	}

}