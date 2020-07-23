<?php
namespace Vinou\SiteBuilder;

use \Vinou\SiteBuilder\Tools\Render;
use \Vinou\SiteBuilder\Processors\Shop;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Session\Session;

/**
* Ajax
*/

class Ajax {

	protected $api = null;
	protected $errors = [];
	protected $result = false;
	protected $request = [];

	public function __construct() {

		$this->api = new Api();

		if (!$this->api || is_null($this->api))
			$this->sendResult(false, 'could not create api connection');

		$this->request = array_merge($_POST, (array)json_decode(trim(file_get_contents('php://input')), true));
	}

	public function run() {

		if (empty($this->request) || !isset($this->request['action']))
			$this->sendResult(false, 'no action defined');

	    $action = $this->request['action'];
	    unset($this->request['action']);
        switch ($action) {
            case 'get':
                $this->sendResult($this->api->getBasket(), 'basket not found');
                break;

            case 'addItem':
                $this->sendResult($this->api->addItemToBasket($this->request), 'item could not be added');
                break;

            case 'editItem':
                $this->sendResult($this->api->editItemInBasket($this->request), 'item could not be updated');
                break;

            case 'deleteItem':
                $this->sendResult($this->api->deleteItemFromBasket($this->request['id']), 'item could not be deleted');
                break;

            case 'findPackage':
                $this->sendResult($this->api->getBasketPackage());
                break;

            case 'findCampaign':
                $result = $this->api->findCampaign($this->request);
                $campaign = Session::getValue('campaign');
                if ($this->result && $campaign && $this->result['uuid'] == $campaign['uuid'])
                	$this->sendResult(false, 'campaign already activated');
                else
                	$this->sendResult($result, 'campaign could not be resolved');
                break;

            case 'loadCampaign':
            	$result = $this->api->findCampaign($this->request);
            	if ($result) {
            		Session::setValue('campaign', $result);
            		$this->sendResult($result);
            	}
            	else
            		$this->sendResult(false, 'campaign could not be resolved');
            	break;

            case 'removeCampaign':
            	$this->sendResult(Session::deleteValue('campaign'), 'campaign could not be deleted');
            	break;

            case 'campaignDiscount':
            	$processor = new Shop($this->api);
            	$this->sendResult($processor->campaignDiscount(), 'discount could not be fetched');
            	break;

            default:
                $this->sendResult(false, 'action could not be resolved');
                break;
        }

	}

	private function sendResult($result, $errorMessage = null) {

		if (!$result) {
			if (is_null($errorMessage))
				$result = ['no result created'];
			else
				array_push($this->errors, $errorMessage);
		}

		if (count($this->errors) > 0)
	    	Render::sendJSON([
	    		'info' => 'error',
	    		'errors' => $this->errors,
	    		'request' => $this->request
	    	], 'error');
	    else
	    	Render::sendJSON($result);

	    exit();
	}

}