<?php
namespace Vinou\Utilities\General;

use \Bramus\Router\Router;
use \Vinou\ApiConnector\Api;
use \Vinou\Utilities\General\Router\DynamicRoutes;
use \Vinou\Utilities\General\Tools\Helper;
use \Vinou\Utilities\General\Tools\Render;
use \Vinou\Utilities\General\Processors\Shop;
use \Vinou\Utilities\General\Processors\Mailer;
use \Vinou\Utilities\General\Processors\Files;

/**
 * Page
 */
class Page {

    protected $routeConfig;
    protected $settingsFile = NULL;
    protected $router;
    public $render;

    function __construct(string $token, string $authId) {
        $this->router = new Router();
        $this->render = new Render();

        $this->render->connect(
            $token,
            $authId
        );

        $this->render->api->initBasket();

        $this->loadDefaultProcessors();

        $this->router->set404(function() {
            header('HTTP/1.1 404 Not Found');
            $options = [
                'pageTitle' => '404 Page Not Found'
            ];
            $this->render->renderPage('404.twig',$options);
        });

        $this->routeConfig = new DynamicRoutes($this->router, $this->render);

        $this->sendCorsHeaders();
    }

    public function setRouteFile($file) {
        $this->routeConfig->setRouteFile($file);
    }

    public function setSettingsFile($file) {
        $this->settingsFile = $file;
    }

    public function loadTemplates($rootDir, $folders = []) {
        $this->render->addTemplateStorages($rootDir, $folders);
    }

    public function run() {
        $this->loadSettings();

        if (is_null($this->routeConfig->routeFile)) {
            $path = str_replace('Classes', '', Helper::getClassPath(get_class($this)));
            $this->routeConfig->setRouteFile($path.'Configuration/routes.yml');
        }

        $this->routeConfig->init();
        $this->router->run();
    }

    private function loadDefaultProcessors() {
        $this->render->loadProcessor(
            'shop', new Shop($this->render->api)
        );
        $this->render->loadProcessor(
            'mailer', new Mailer()
        );
        $this->render->loadProcessor(
            'files', new Files()
        );
    }

    private function loadSettings() {
        if (is_null($this->settingsFile))
            return;

        $absFile = substr($this->settingsFile, 0, 1) == '/' ? $this->settingsFile : Helper::getNormDocRoot().VINOU_CONFIG_DIR.$this->settingsFile;
        if (!is_file($absFile))
            throw new \Exception('Settings file '.$absFile.' could not be resolved');


        $settings = spyc_load_file($absFile);
        if (isset($settings['additionalContent']))
            $this->render->dataProcessing($settings['additionalContent']);

        if (isset($settings['settings']))
            $this->render->renderArr['settings'] = $settings['settings'];

        return;
    }

    private function sendCorsHeaders() {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Headers: X-Requested-With,content-type, Authorization, Content-Type, Accept");
        header("Access-Control-Allow-Methods: GET,HEAD,PUT,PATCH,POST,DELETE");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
    }
}