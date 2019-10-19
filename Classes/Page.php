<?php
namespace Vinou\Page;

use \Bramus\Router\Router;
use \Vinou\ApiConnector\Api;
use \Vinou\Page\Router\DynamicRoutes;
use \Vinou\Page\Tools\Helper;
use \Vinou\Page\Tools\Render;
use \Vinou\Page\Processors\Shop;
use \Vinou\Page\Processors\Mailer;
use \Vinou\Page\Processors\Files;

/**
 * Page
 */
class Page {

    protected $routeConfig;
    protected $settingsFile = NULL;
    protected $router;
    protected $config = NULL;
    protected $settings = NULL;
    public $loadDefaults = true;
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

        if (isset($this->config['load']['defaultRoutes']))
            $this->routeConfig->setDefaults($this->config['load']['defaultRoutes']);

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

    private function loadDefaultSettings() {
        $settingsFile = str_replace('Classes', 'Configuration', Helper::getClassPath(get_class($this))).'/settings.yml';
        $this->settings = spyc_load_file($settingsFile);
    }

    private function loadAdditionalSettings() {

        $absFile = substr($this->settingsFile, 0, 1) == '/' ? $this->settingsFile : Helper::getNormDocRoot().VINOU_CONFIG_DIR.$this->settingsFile;
        if (!is_file($absFile))
            throw new \Exception('Settings file '.$absFile.' could not be resolved');

        $additionalSettings = spyc_load_file($absFile);

        $this->settings = is_null($this->settings) ? $additionalSettings : array_replace_recursive($this->settings, $additionalSettings);
    }

    private function parseSettings() {
        if (is_null($this->settings))
            throw new \Exception('Settings parsing error');

        if (isset($this->settings['system']))
            $this->config = $this->settings['system'];

        if (isset($this->settings['additionalContent']))
            $this->render->dataProcessing($this->settings['additionalContent']);

        if (isset($this->settings['settings']))
            $this->render->renderArr['settings'] = $this->settings['settings'];
    }

    private function loadSettings() {

        if ($this->loadDefaults)
            $this->loadDefaultSettings();

        if (!is_null($this->settingsFile))
            $this->loadAdditionalSettings();

        $this->parseSettings();
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