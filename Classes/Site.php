<?php
namespace Vinou\SiteBuilder;

use \Bramus\Router\Router;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Vinou\ApiConnector\Services\ServiceLocator;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\SiteBuilder\Router\DynamicRoutes;
use \Vinou\SiteBuilder\Tools\Render;
use \Vinou\SiteBuilder\Loader;
use \Vinou\SiteBuilder\Processors\Admin;
use \Vinou\SiteBuilder\Processors\External;
use \Vinou\SiteBuilder\Processors\Formatter;
use \Vinou\SiteBuilder\Processors\Files;
use \Vinou\SiteBuilder\Processors\Instagram;
use \Vinou\SiteBuilder\Processors\Mailer;
use \Vinou\SiteBuilder\Processors\Shop;
use \Vinou\SiteBuilder\Processors\Sitemap;
use \Vinou\SiteBuilder\Tools\ImageService;

/**
 * Application bootstrap for SiteBuilder-based projects.
 *
 * Wires together the router, renderer, settings loader, and all default
 * processors. Exposes the main entry points: loadTheme() to register theme
 * assets and routes, and run() to start the request dispatch loop.
 */
class Site {

    /** @var DynamicRoutes Route configuration and registration manager. */
    protected DynamicRoutes $routeConfig;

    /** @var Router Underlying bramus/router instance. */
    protected Router $router;

    /** @var array<string, mixed>|null Merged 'system' settings block, set during initialize(). */
    protected ?array $config = null;

    /** @var string|null Identifier of the active theme. */
    protected ?string $themeID = null;

    /** @var string|null Absolute path to the active theme directory (with trailing slash). */
    protected ?string $themeDir = null;

    /** @var object|null Settings service from the service locator. */
    public ?object $settingsService = null;

    /**
     * Controls which built-in default route files are loaded.
     * Passed through to DynamicRoutes::setDefaults() in run().
     *
     * @var bool|list<string>
     */
    public bool|array $loadDefaults = true;

    /** @var Render Render instance; public to allow external access to processors and renderArr. */
    public Render $render;

    public function __construct() {
        $this->router         = new Router();
        $this->render         = new Render();
        $this->settingsService = ServiceLocator::get('Settings');

        $this->render->connect();
        $this->routeConfig = new DynamicRoutes($this->router, $this->render);

        $this->sendCorsHeaders();
    }

    /**
     * Runs the full bootstrap sequence and dispatches the current request.
     *
     * Order: initialize settings → load default storages → apply route
     * defaults → pass additionalContent to route config → init routes → run router.
     *
     * @return void
     */
    public function run(): void {
        $this->initialize();
        $this->render->loadDefaultStorages();

        if (isset($this->config['load']['defaultRoutes']))
            $this->routeConfig->setDefaults($this->config['load']['defaultRoutes']);

        $additionalContent = $this->settingsService->get('additionalContent');
        if (is_array($additionalContent))
            $this->routeConfig->setAdditionalContent($additionalContent);

        $this->loadAdminPanel();
        $this->registerImageProxy();
        $this->routeConfig->init();
        $this->router->run();
    }

    /**
     * Registers the project-level route configuration file.
     *
     * @param string $file  Absolute path or path relative to VINOU_CONFIG_DIR.
     * @return void
     */
    public function setRouteFile(string $file): void {
        $this->routeConfig->setRouteFile($file);
    }

    /**
     * Adds custom Twig template directories to the renderer.
     *
     * @param string        $rootDir  Base directory containing the template folders.
     * @param list<string>  $folders  Sub-folder names to register (e.g. ['Layouts/', 'Templates/']).
     * @return void
     */
    public function loadTemplates(string $rootDir, array $folders = []): void {
        $this->render->addTemplateStorages($rootDir, $folders);
    }

    /**
     * Loads a theme: registers template directories and optionally its routes.
     *
     * Template folders registered: Layouts/, Partials/, Templates/ under
     * $themeDir/Resources/. Route files are loaded from $themeDir/Configuration/Routes/.
     *
     * @param string $themeID    Identifier for the theme (stored for reference).
     * @param string $themeDir   Absolute path to the theme root (with trailing slash).
     * @param bool   $loadRoutes Whether to register the theme's route files (default: true).
     * @return void
     */
    public function loadTheme(string $themeID, string $themeDir, bool $loadRoutes = true): void {
        $this->themeID  = $themeID;
        $this->themeDir = $themeDir;

        $themeFolders = ['Layouts/', 'Partials/', 'Templates/'];
        $this->render->loadDefaultStorages();
        $this->render->addTemplateStorages($themeDir . 'Resources/', $themeFolders);

        if ($loadRoutes)
            $this->routeConfig->loadRoutesByDirectory($themeDir . 'Configuration/Routes/');
    }

    /**
     * Loads settings, configures the 404 handler, applies shop settings, and
     * registers all default processors.
     *
     * @return void
     */
    private function initialize(): void {
        $loader = new Loader\Settings();

        if (!is_null($this->themeDir))
            $loader->addByDirectory($this->themeDir);

        $loader->load();

        $config = $this->settingsService->get('system');
        if (is_array($config))
            $this->config = $config;

        $this->router->set404(function() {
            header('HTTP/1.1 404 Not Found');
            $options = ['pageTitle' => '404 Page Not Found'];

            $additionalContent = $this->settingsService->get('additionalContent');
            if (is_array($additionalContent))
                $this->render->dataProcessing($additionalContent);

            if (isset($this->config['pageNotFound'])) {
                $config404 = $this->config['pageNotFound'];

                if (isset($config404['template']))
                    $this->render->renderPage($config404['template'], $options);
                elseif (isset($config404['type'])) {
                    switch ($config404['type']) {
                        case 'redirect':
                        default:
                            Redirect::internal($config404['target']);
                            break;
                    }
                } else {
                    $this->render->renderPage('404.twig', $options);
                }
            } else {
                $this->render->renderPage('404.twig', $options);
            }
        });

        $settings = $this->settingsService->get('settings');
        if (is_array($settings)) {
            $this->render->renderArr['settings'] = $settings;
            $this->render->setConfig($settings);
            Session::setValue('settings', $settings);
        }

        $this->loadDefaultProcessors();
    }

    /**
     * Registers all built-in processors with the renderer.
     *
     * Registered keys: shop, mailer, files, external, instagram, sitemap, formatter.
     *
     * @return void
     */
    private function loadDefaultProcessors(): void {
        $this->render->loadProcessor('shop',   new Shop($this->render->api));

        $mailer = new Mailer($this->render->api);
        if (!is_null($this->themeDir))
            $mailer->addThemeMailStorage($this->themeDir . 'Resources/', ['Mail/']);
        $this->render->loadProcessor('mailer', $mailer);

        $this->render->loadProcessor('files',     new Files());
        $this->render->loadProcessor('external',  new External());
        $this->render->loadProcessor('instagram', new Instagram());
        $this->render->loadProcessor('sitemap',   new Sitemap($this->routeConfig, $this->render->api));
        $this->render->loadProcessor('formatter', new Formatter());
    }

    /**
     * Registers the /image-proxy route for on-demand image caching.
     *
     * The |image Twig filter emits /image-proxy?src=...&chstamp=... URLs instead
     * of downloading images during page rendering. This handler downloads the image
     * from the Vinou API on first request, caches it locally, and serves it with
     * long-lived cache headers. Subsequent requests are served directly from cache.
     *
     * @return void
     */
    private function registerImageProxy(): void {
        $settings = ['system' => $this->settingsService->get('system') ?? []];
        $this->router->get('/image-proxy', function() use ($settings) {
            $src      = $_GET['src'] ?? '';
            $chstamp  = $_GET['chstamp'] ?? 'now';
            $dimRaw   = $_GET['dim'] ?? null;
            $dimension = null;
            if ($dimRaw !== null) {
                if (str_contains($dimRaw, 'x')) {
                    [$w, $h]   = explode('x', $dimRaw, 2);
                    $dimension = [(int)$w, (int)$h];
                } else {
                    $dimension = (int)$dimRaw;
                }
            }
            ImageService::serveProxy($src, $chstamp, $dimension, $settings);
        });
    }

    /**
     * Bootstraps the admin panel when system.password or system.users is configured.
     *
     * Registers the Admin processor and loads the system route definitions from
     * Configuration/Routes/Admin/system.yml into the route configuration so they
     * are picked up by DynamicRoutes::init(). The static asset route for CSS/JS
     * is registered directly since it serves files rather than rendered templates.
     *
     * @return void
     */
    private function loadAdminPanel(): void {
        $system = $this->settingsService->get('system') ?? [];

        if (!isset($system['password']) && empty($system['users']))
            return;

        $admin = new Admin($this->routeConfig, $this->themeDir);
        $this->render->loadProcessor('admin', $admin);

        $this->routeConfig->loadRouteFile(__DIR__ . '/../Configuration/Routes/Admin/system.yml');

        $publicDir = __DIR__ . '/../Resources/Public';
        $this->router->get('/system/assets/(.*)', function(string $file) use ($publicDir) {
            if (strpos($file, '..') !== false) {
                header('HTTP/1.1 403 Forbidden');
                exit;
            }

            $path = $publicDir . '/' . ltrim($file, '/');
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $types = [
                'css'   => 'text/css; charset=UTF-8',
                'js'    => 'application/javascript; charset=UTF-8',
                'svg'   => 'image/svg+xml',
                'woff'  => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'   => 'font/ttf',
                'eot'   => 'application/vnd.ms-fontobject',
            ];

            if (!isset($types[$ext]) || !is_file($path)) {
                header('HTTP/1.1 404 Not Found');
                exit;
            }

            header_remove('Cache-Control');
            header_remove('Pragma');
            header('Content-Type: ' . $types[$ext]);
            header('Cache-Control: public, max-age=86400, immutable');
            readfile($path);
            exit;
        });
    }

    /**
     * Sends CORS and cache-control headers for every request.
     *
     * @return void
     */
    private function sendCorsHeaders(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-Requested-With,content-type, Authorization, Content-Type, Accept');
        header('Access-Control-Allow-Methods: GET,HEAD,PUT,PATCH,POST,DELETE');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }
}
