<?php
namespace Vinou\SiteBuilder;

use \Vinou\SiteBuilder\Tools\Render;
use \Vinou\SiteBuilder\Processors\Shop;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Services\ServiceLocator;

/**
 * Entry point for shop AJAX requests (ajax.php).
 *
 * Bootstraps the API connection and settings, then dispatches to action
 * handlers based on the 'action' field in the merged POST/JSON request body.
 * Every code path ends by calling sendResult(), which outputs JSON and exits.
 */
class Ajax {

    /** @var Api|null Vinou API instance. */
    protected ?Api $api = null;

    /** @var object|null Settings service from the service locator. */
    protected ?object $settingsService = null;

    /** @var list<string> Accumulated error messages for the current request. */
    protected array $errors = [];

    /** @var mixed Reserved for external inspection of the last result. */
    protected mixed $result = false;

    /** @var array<string, mixed> Merged request data from POST and JSON body. */
    protected array $request = [];

    /**
     * @param string|null $themeDir  Absolute path to the theme directory for settings loading.
     */
    public function __construct(?string $themeDir = null) {
        $this->api = new Api();

        if ($this->api->connected === false)
            $this->sendResult(false, 'could not create api connection');

        $this->request = array_merge(
            $_POST,
            (array)json_decode(trim(file_get_contents('php://input')), true)
        );

        $this->settingsService = ServiceLocator::get('Settings');
        $this->loadSettings($themeDir);
    }

    /**
     * Initialises the settings loader with the given theme directory.
     *
     * @param string|null $dir  Absolute path to the theme directory, or null to skip.
     * @return void
     */
    public function loadSettings(?string $dir): void {
        $loader = new Loader\Settings();

        if (!is_null($dir))
            $loader->addByDirectory($dir);

        $loader->load();
    }

    /**
     * Dispatches the incoming request to the matching action handler.
     *
     * Reads 'action' from the merged request data and routes to the
     * corresponding API or session operation. Always terminates via sendResult().
     *
     * @return void
     */
    public function run(): void {
        if (empty($this->request) || !isset($this->request['action']))
            $this->sendResult(false, 'no action defined');

        $action = $this->request['action'];
        unset($this->request['action']);

        switch ($action) {
            case 'get':
                $result = $this->api->getBasket();
                if (!$result) {
                    $this->sendResult(false, 'basket not found', 400);
                } else {
                    $result['quantity'] = Shop::calcCardQuantity($result['basketItems']);

                    $settings = $this->settingsService->get('settings');
                    if (isset($settings['basketPerWinery']) && $settings['basketPerWinery'] !== false)
                        $result['valid'] = Shop::quantityByWineryIsAllowed($result['basketItems'], true, $settings);
                    else
                        $result['valid'] = Shop::quantityIsAllowed($result['quantity'], true, $settings);
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
                Session::setValue('delivery_type', 'address');
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
                    $this->sendResult(array_merge($settings, ['delivery_type' => Session::getValue('delivery_type')]));
                break;

            default:
                $this->sendResult(false, 'action could not be resolved');
                break;
        }
    }

    /**
     * Calls the API findCampaign endpoint and clears an invalid campaign from the session.
     *
     * When the API returns an error code, the stored campaign session value is
     * deleted and sendResult() is called immediately with the error detail.
     *
     * @return array<string, mixed>|false  Campaign result array, or false if not found.
     */
    private function validateCampaign(): array|false {
        $result   = $this->api->findCampaign($this->request);
        $campaign = Session::getValue('campaign');

        if ($result && isset($result['code'])) {
            Session::deleteValue('campaign');
            $this->sendResult(false, isset($result['data']) ? $result['data'] : $result['details']);
        }

        return $result;
    }

    /**
     * Outputs a JSON response and terminates the process.
     *
     * When $result is falsy and $errorMessage is given, the message is appended
     * to the error list and a JSON error response is sent with $errorCode.
     * Otherwise a standard JSON success response is sent.
     *
     * @param mixed       $result        The result payload to send.
     * @param string|null $errorMessage  Error message to include when $result is falsy.
     * @param int         $errorCode     HTTP status code for error responses (default 409).
     * @return never
     */
    private function sendResult(mixed $result, ?string $errorMessage = null, int $errorCode = 409): never {
        if (!$result) {
            if (is_null($errorMessage))
                $result = ['no result created'];
            else
                array_push($this->errors, $errorMessage);
        }

        if (count($this->errors) > 0)
            Render::sendJSONError([
                'info'    => 'error',
                'errors'  => $this->errors,
                'request' => $this->request
            ], $errorCode);
        else
            Render::sendJSON($result);

        exit();
    }
}
