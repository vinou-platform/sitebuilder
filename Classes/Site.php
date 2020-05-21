<?php
namespace Vinou\SiteBuilder;

use \Bramus\Router\Router;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\SiteBuilder\Router\DynamicRoutes;
use \Vinou\SiteBuilder\Tools\Render;
use \Vinou\SiteBuilder\Processors\External;
use \Vinou\SiteBuilder\Processors\Formatter;
use \Vinou\SiteBuilder\Processors\Files;
use \Vinou\SiteBuilder\Processors\Mailer;
use \Vinou\SiteBuilder\Processors\Shop;
use \Vinou\SiteBuilder\Processors\Sitemap;

/**
 * Site
 */
class Site {

    protected $routeConfig;
    protected $settingsFile = NULL;
    protected $router;
    protected $config = NULL;
    protected $settings = [];
    protected $themeID = NULL;
    protected $themeRoot = NULL;
    public $loadDefaults = true;
    public $render;

    function __construct() {
        $this->router = new Router();
        $this->render = new Render();

        $this->render->connect();
        $this->routeConfig = new DynamicRoutes($this->router, $this->render);

        $this->loadDefaultProcessors();

        $this->router->set404(function() {
            header('HTTP/1.1 404 Not Found');
            $options = [
                'pageTitle' => '404 Page Not Found'
            ];
            $this->render->renderPage('404.twig',$options);
        });


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

    public function loadTheme($themeID, $themeDir) {
        $this->themeID = $themeID;
        $this->themeDir = $themeDir;
        $themeFolders = ['Layouts/', 'Partials/', 'Templates/'];
        $this->render->loadDefaultStorages();
        $this->render->addTemplateStorages($themeDir.'Resources/', $themeFolders);
        $this->routeConfig->setRouteStorage($themeDir.'Configuration/Routes/');
    }

    public function run() {
        $this->loadSettings();
        $this->render->loadDefaultStorages();

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
            'mailer', new Mailer($this->render->api)
        );
        $this->render->loadProcessor(
            'files', new Files()
        );
        $this->render->loadProcessor(
            'external', new External()
        );
        $this->render->loadProcessor(
            'sitemap', new Sitemap(
                $this->routeConfig,
                $this->render->api
            )
        );
        $this->render->loadProcessor(
            'formatter', new Formatter()
        );
    }

    private function loadDefaultSettings() {
        $settingsFile = str_replace('Classes', 'Configuration', Helper::getClassPath(get_class($this))).'/settings.yml';
        if (!is_null($this->themeID) && !is_null($this->themeDir)) {
            $themeSettings = Helper::getNormDocRoot().$this->themeDir.'/Configuration/settings.yml';
            if (is_file($themeSettings))
                $settingsFile = $themeSettings;
        }

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

        if (isset($this->settings['settings'])) {
            $this->render->renderArr['settings'] = $this->settings['settings'];
            $this->render->setConfig($this->settings['settings']);
            Session::setValue('settings', $this->settings['settings']);
        }
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