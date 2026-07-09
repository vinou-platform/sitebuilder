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
        $this->registerStatusEndpoint();
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
     * Registers the protected system status endpoint at GET /system/status.
     *
     * Returns a compact JSON snapshot of the runtime for external monitoring
     * (PHP version, last deployment, installed vinou package versions). The
     * endpoint is always registered but only answers with data when the request
     * carries the shared secret configured under system.statusSecret; otherwise
     * it responds 401 without leaking any information. When no secret is
     * configured the endpoint is effectively disabled (always 401).
     *
     * Authentication accepts either an "Authorization: Bearer <secret>" header
     * or an "X-Status-Token: <secret>" header (fallback for hosts that strip
     * the Authorization header). Comparison is constant-time via hash_equals().
     *
     * @return void
     */
    private function registerStatusEndpoint(): void {
        $system = $this->settingsService->get('system') ?? [];
        $secret = isset($system['statusSecret']) ? (string) $system['statusSecret'] : '';

        $this->router->get('/system/status', function() use ($secret) {
            $provided = $this->extractStatusToken();

            if ($secret === '' || $provided === null || !hash_equals($secret, $provided)) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json');
                header('WWW-Authenticate: Bearer realm="status"');
                echo json_encode(['ok' => false, 'error' => 'unauthorized']);
                exit();
            }

            Render::sendJSON($this->collectSystemStatus());
        });
    }

    /**
     * Extracts the status token from the incoming request headers.
     *
     * Prefers the standard "Authorization: Bearer <token>" header and falls
     * back to a custom "X-Status-Token" header for environments where the
     * Authorization header is not forwarded to PHP.
     *
     * @return string|null  The provided token, or null when none was sent.
     */
    private function extractStatusToken(): ?string {
        $authorization = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($authorization === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches))
            return trim($matches[1]);

        $custom = $_SERVER['HTTP_X_STATUS_TOKEN'] ?? '';
        if ($custom !== '')
            return trim($custom);

        return null;
    }

    /**
     * Collects the full system status payload returned by the status endpoint.
     *
     * Intentionally comprehensive so that the consuming monitoring dashboard can
     * surface additional details later without requiring a redeploy of every
     * shop. The `schema` field is bumped whenever the payload structure changes.
     *
     * @return array<string, mixed>
     */
    private function collectSystemStatus(): array {
        return [
            'ok'         => true,
            'schema'     => 1,
            'time'       => date('c'),
            'deployment' => $this->readDeployInfo(),
            'php'        => $this->collectPhpInfo(),
            'system'     => $this->collectSystemInfo(),
            'packages'   => $this->collectPackageVersions(),
        ];
    }

    /**
     * Collects PHP runtime details: version, SAPI, architecture, loaded
     * extensions, the complete INI configuration, OPcache state and the
     * versions of the most relevant bundled libraries.
     *
     * @return array<string, mixed>
     */
    private function collectPhpInfo(): array {
        return [
            'version'      => PHP_VERSION,
            'versionId'    => PHP_VERSION_ID,
            'major'        => PHP_MAJOR_VERSION,
            'minor'        => PHP_MINOR_VERSION,
            'release'      => PHP_RELEASE_VERSION,
            'sapi'         => PHP_SAPI,
            'zend'         => zend_version(),
            'architecture' => PHP_INT_SIZE * 8,
            'extensions'   => $this->collectExtensions(),
            'ini'          => $this->collectIni(),
            'opcache'      => $this->collectOpcacheInfo(),
            'libraries'    => $this->collectLibraries(),
        ];
    }

    /**
     * Returns all loaded PHP extensions, sorted for stable output.
     *
     * @return list<string>
     */
    private function collectExtensions(): array {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING | SORT_FLAG_CASE);
        return array_values($extensions);
    }

    /**
     * Returns the complete PHP INI configuration (every directive with its
     * current value). Falls back to a curated subset when ini_get_all() is
     * disabled on the host.
     *
     * @return array<string, mixed>
     */
    private function collectIni(): array {
        $all = @ini_get_all(null, false);
        if (is_array($all)) {
            ksort($all);
            return $all;
        }

        $keys = [
            'memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars',
            'upload_max_filesize', 'post_max_size', 'max_file_uploads', 'file_uploads',
            'display_errors', 'display_startup_errors', 'log_errors', 'error_reporting',
            'error_log', 'date.timezone', 'default_charset', 'default_socket_timeout',
            'allow_url_fopen', 'short_open_tag', 'session.save_handler',
            'session.gc_maxlifetime', 'session.cookie_secure', 'opcache.enable',
            'opcache.memory_consumption', 'opcache.jit', 'opcache.jit_buffer_size',
            'realpath_cache_size', 'output_buffering',
        ];

        $out = [];
        foreach ($keys as $key)
            $out[$key] = ini_get($key);

        return $out;
    }

    /**
     * Returns a compact summary of the OPcache state, or ['enabled' => false]
     * when OPcache is unavailable or its API is restricted.
     *
     * @return array<string, mixed>
     */
    private function collectOpcacheInfo(): array {
        if (!function_exists('opcache_get_status'))
            return ['enabled' => false];

        $status = @opcache_get_status(false);
        if (!is_array($status))
            return ['enabled' => false];

        $info = ['enabled' => (bool) ($status['opcache_enabled'] ?? false)];

        if (isset($status['memory_usage']) && is_array($status['memory_usage'])) {
            $memory = $status['memory_usage'];
            $info['memoryUsedMb'] = round(($memory['used_memory'] ?? 0) / 1048576, 1);
            $info['memoryFreeMb'] = round(($memory['free_memory'] ?? 0) / 1048576, 1);
        }

        if (isset($status['opcache_statistics']) && is_array($status['opcache_statistics'])) {
            $stats = $status['opcache_statistics'];
            $info['cachedScripts'] = $stats['num_cached_scripts'] ?? null;
            $info['hitRate']       = isset($stats['opcache_hit_rate'])
                ? round((float) $stats['opcache_hit_rate'], 2)
                : null;
        }

        if (function_exists('opcache_get_configuration')) {
            $config = @opcache_get_configuration();
            if (is_array($config) && isset($config['directives']['opcache.jit']))
                $info['jit'] = $config['directives']['opcache.jit'];
        }

        return $info;
    }

    /**
     * Returns the versions of a few security-relevant bundled libraries.
     *
     * @return array<string, string>
     */
    private function collectLibraries(): array {
        $libraries = [];

        if (function_exists('curl_version')) {
            $curl = @curl_version();
            if (is_array($curl)) {
                if (isset($curl['version']))     $libraries['curl'] = $curl['version'];
                if (isset($curl['ssl_version'])) $libraries['curlSsl'] = $curl['ssl_version'];
            }
        }

        if (defined('OPENSSL_VERSION_TEXT'))
            $libraries['openssl'] = OPENSSL_VERSION_TEXT;
        if (defined('INTL_ICU_VERSION'))
            $libraries['icu'] = INTL_ICU_VERSION;
        if (defined('LIBXML_DOTTED_VERSION'))
            $libraries['libxml'] = LIBXML_DOTTED_VERSION;

        return $libraries;
    }

    /**
     * Collects host/server details: hostname, OS, web server, document root and
     * disk usage of the deployment volume.
     *
     * @return array<string, mixed>
     */
    private function collectSystemInfo(): array {
        $root = defined('VINOU_ROOT') ? VINOU_ROOT : getcwd();

        $info = [
            'hostname'       => gethostname() ?: null,
            'os'             => PHP_OS,
            'osRelease'      => php_uname('r'),
            'machine'        => php_uname('m'),
            'serverSoftware' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'documentRoot'   => $_SERVER['DOCUMENT_ROOT'] ?? $root,
        ];

        $free = @disk_free_space($root);
        if ($free !== false)
            $info['diskFreeMb'] = round($free / 1048576);

        $total = @disk_total_space($root);
        if ($total !== false)
            $info['diskTotalMb'] = round($total / 1048576);

        return $info;
    }

    /**
     * Reads the pretty version strings of every installed Composer package via
     * Composer's runtime metadata, sorted by package name.
     *
     * @return array<string, string>
     */
    private function collectPackageVersions(): array {
        $versions = [];
        if (!class_exists(\Composer\InstalledVersions::class))
            return $versions;

        try {
            foreach (\Composer\InstalledVersions::getInstalledPackages() as $package) {
                try {
                    $versions[$package] = \Composer\InstalledVersions::getPrettyVersion($package) ?? 'dev';
                } catch (\Throwable) {
                    // Ignore packages that cannot be resolved.
                }
            }
        } catch (\Throwable) {
            // Composer runtime metadata unavailable.
        }

        ksort($versions);
        return $versions;
    }

    /**
     * Reads the last line of the deployment marker file (deploy.txt) written by
     * the CI pipeline on every deploy. The file lives at the project root, one
     * level above the web root, and grows with one line per deployment:
     *
     *   Version:<shortSha>;Date:<ISO8601>;Commit:<fullSha>
     *
     * @return array{date: ?string, version: ?string, commit: ?string}
     */
    private function readDeployInfo(): array {
        $info = ['date' => null, 'version' => null, 'commit' => null];

        $root = defined('VINOU_ROOT') ? VINOU_ROOT : getcwd();
        $candidates = [
            dirname($root) . '/deploy.txt',
            $root . '/deploy.txt',
        ];

        $file = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $file = $candidate;
                break;
            }
        }

        if ($file === null)
            return $info;

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === [])
            return $info;

        $last = trim((string) end($lines));
        foreach (explode(';', $last) as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2)
                continue;

            $key   = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (array_key_exists($key, $info))
                $info[$key] = $value;
        }

        return $info;
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
