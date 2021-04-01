<?php
namespace Vinou\SiteBuilder;

use \Vinou\SiteBuilder\Tools\Render;
use \Vinou\SiteBuilder\Processors\Shop;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Services\ServiceLocator;

/**
* Ajax
*/

class Ajax {

    protected $api = null;
    protected $settingsService = null;
    protected $errors = [];
    protected $result = false;
    protected $request = [];

    public function __construct($themeDir = null, $defaults = true) {

        $this->api = new Api();

        if (!$this->api || is_null($this->api))
            $this->sendResult(false, 'could not create api connection');

        $this->request = array_merge($_POST, (array)json_decode(trim(file_get_contents('php://input')), true));

        $this->settingsService = ServiceLocator::get('Settings');
        $this->loadSettings($themeDir, $defaults);
    }

    public function loadSettings($dir, $defaults) {
        $loader = new Loader\Settings($defaults);

        if (!is_null($dir))
            $loader->addByDirectory($dir);

        $loader->load();
    }

    public function run() {

        if (empty($this->request) || !isset($this->request['action']))
            $this->sendResult(false, 'no action defined');

        $action = $this->request['action'];
        unset($this->request['action']);
        switch ($action) {
            case 'get':
                $result = $this->api->getBasket();
                if (!$result)
                    $this->sendResult(false, 'basket not found', 400);
                else {
                    $result['quantity'] = Shop::calcCardQuantity($result['basketItems']);
                    $result['valid'] = Shop::quantityIsAllowed($result['quantity'], true);
                }

                $this->sendResult($result);
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
                Session::setValue('delivery_type','address');
                $this->sendResult($this->api->getBasketPackage(), 'package not found', 400);
                break;

            case 'findCampaign':
                $result = $this->validateCampaign();
                $this->sendResult($result, 'campaign could not be resolved', 400);
                break;

            case 'loadCampaign':
                $result = $this->validateCampaign();
                if (isset($result['data']))
                    Session::setValue('campaign', $result['data']);
                $this->sendResult($result, 'campaign could not be resolved', 400);
                break;

            case 'setDeliveryType':
                if (isset($this->request['delivery_type']))
                    $this->sendResult(Session::setValue('delivery_type', $this->request['delivery_type']));
                else
                    $this->sendResult(false, 'delivery type could not be set');
                break;

            case 'removeCampaign':
                $this->sendResult(Session::deleteValue('campaign'), 'campaign could not be deleted');
                break;

            case 'campaignDiscount':
                $processor = new Shop($this->api);
                $this->sendResult($processor->campaignDiscount(), 'discount could not be fetched', 400);
                break;

            case 'settings':
                $settings = $this->settingsService->get('settings');
                if (!$settings)
                    $this->sendResult(false, 'delivery type could not be set');
                else
                    $this->sendResult(array_merge($settings,['delivery_type' => Session::getValue('delivery_type')]));
                break;

            default:
                $this->sendResult(false, 'action could not be resolved');
                break;
        }

    }

    private function validateCampaign() {
        $result = $this->api->findCampaign($this->request);
        $campaign = Session::getValue('campaign');

        if ($result && isset($result['code']))
            $this->sendResult(false, isset($result['data']) ? $result['data'] : $result['details']);

        else if ($result && $campaign && $result['data']['uuid'] == $campaign['uuid'])
            $this->sendResult(false, 'campaign already activated');

        return $result;
    }

    private function sendResult($result, $errorMessage = null, $errorCode = 409) {

        if (!$result) {
            if (is_null($errorMessage))
                $result = ['no result created'];
            else
                array_push($this->errors, $errorMessage);
        }

        if (count($this->errors) > 0)
            Render::sendJSONError([
                'info' => 'error',
                'errors' => $this->errors,
                'request' => $this->request
            ], $errorCode);
        else
            Render::sendJSON($result);

        exit();
    }

}