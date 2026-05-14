<?php
namespace Vinou\SiteBuilder\Router;

use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\SiteBuilder\Tools\Render;
use \Vinou\SiteBuilder\Processors\Sitemap;
use \Symfony\Component\Yaml\Yaml;

/**
 * Builds and registers all application routes with the underlying router.
 *
 * Manages three layers of route configuration: SiteBuilder built-in defaults
 * (lowest priority), theme routes, and project-level overrides (highest
 * priority). Supports deep-merge via the 'extend' directive and per-route
 * additionalContent exclusions via 'excludeContent'.
 *
 * @see https://github.com/bramus/router
 */
class DynamicRoutes {

    /** @var object Underlying bramus/router instance. */
    private object $router;

    /** @var Render Render instance used inside route callbacks. */
    private Render $render;

    /**
     * Controls which built-in default route files are loaded.
     *
     * true  = load all files in Configuration/Routes/
     * false = skip defaults entirely
     * array = load only the listed filenames (without .yml extension)
     *
     * @var bool|list<string>
     */
    private bool|array $loadDefaults = true;

    /** @var array<string, mixed> Global data loaded before every page route render. */
    private array $additionalContent = [];

    /** @var array<string, mixed> Merged route configuration (pattern → options). */
    public array $configuration = [];

    /** @var string|null Absolute path to the project-level route override file. */
    public ?string $routeFile = null;

    /**
     * @param object $router  Initialized bramus/router instance.
     * @param Render $render  Initialized Render instance.
     */
    public function __construct(object $router, Render $render) {
        $this->router = $router;
        $this->render = $render;
    }

    /**
     * Sets the global data that is loaded before every page route render.
     *
     * @param array<string, mixed> $content  additionalContent map from settings.
     * @return void
     */
    public function setAdditionalContent(array $content): void {
        $this->additionalContent = $content;
    }

    /**
     * Sets the path to the project-level route configuration file.
     *
     * @param string $file  Absolute path or path relative to VINOU_CONFIG_DIR.
     * @return void
     */
    public function setRouteFile(string $file): void {
        $this->routeFile = $file;
    }

    /**
     * Loads all route layers in order and registers them with the router.
     *
     * Load order (earlier = lower priority):
     *   1. SiteBuilder defaults (if loadDefaults is true or an array)
     *   2. Theme routes (loaded externally via loadRoutesByDirectory before init())
     *   3. Project route overrides (routeFile, via loadAdditionalRoutes)
     *
     * @return void
     */
    public function init(): void {
        if ($this->loadDefaults)
            $this->loadDefaultRoutes();

        if (!is_null($this->routeFile))
            $this->loadAdditionalRoutes();

        if (defined('VINOU_CONFIG_DIR')) {
            $redirectsFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'redirects.yml';
            if (is_file($redirectsFile))
                $this->loadRouteFile($redirectsFile);
        }

        $this->generateRoutes();
    }

    /**
     * Returns the current loadDefaults setting.
     *
     * @return bool|list<string>
     */
    public function getDefaults(): bool|array {
        return $this->loadDefaults;
    }

    /**
     * Overrides the loadDefaults setting.
     *
     * @param bool|list<string> $status  See $loadDefaults property for accepted values.
     * @return void
     */
    public function setDefaults(bool|array $status): void {
        $this->loadDefaults = $status;
    }

    /**
     * Prepends built-in default route files as the base layer.
     *
     * When loadDefaults is true, all .yml files in Configuration/Routes/ are
     * loaded. When it is an array, only the named files are loaded.
     *
     * @return void
     */
    public function loadDefaultRoutes(): void {
        $exampleConfigDir = str_replace('Classes/Router', 'Configuration/Routes', Helper::getClassPath(get_class($this)));

        if (!$this->loadDefaults)
            return;

        if (is_bool($this->loadDefaults)) {
            $this->loadDefaultRoutesByDirectory($exampleConfigDir);
            return;
        }

        if (is_array($this->loadDefaults)) {
            foreach ($this->loadDefaults as $fileName) {
                $file = $exampleConfigDir . '/' . $fileName . '.yml';
                $this->prependRoutesByFile($file);
            }
        }
    }

    /**
     * Appends all .yml route files from a directory as a high-priority layer.
     *
     * Used by Site::loadTheme() to register theme routes. Skips silently if
     * $dir does not exist.
     *
     * @param string $dir  Absolute or webroot-relative path to a directory of .yml files.
     * @return void
     */
    public function loadRoutesByDirectory(string $dir): void {
        if (strpos($dir, Helper::getNormDocRoot()) === false)
            $dir = Helper::getNormDocRoot() . $dir;

        if (!is_dir($dir))
            return;

        if ($handle = opendir($dir)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..' && pathinfo($entry, PATHINFO_EXTENSION) === 'yml')
                    $this->appendRoutesByFile($dir . '/' . $entry);
            }
            closedir($handle);
        }
    }

    /**
     * Prepends all .yml route files from a directory as the lowest-priority layer.
     *
     * Existing configuration always wins over files loaded here. Skips silently
     * if $dir does not exist.
     *
     * @param string $dir  Absolute or webroot-relative path to a directory of .yml files.
     * @return void
     */
    private function loadDefaultRoutesByDirectory(string $dir): void {
        if (strpos($dir, Helper::getNormDocRoot()) === false)
            $dir = Helper::getNormDocRoot() . $dir;

        if (!is_dir($dir))
            return;

        if ($handle = opendir($dir)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..' && pathinfo($entry, PATHINFO_EXTENSION) === 'yml')
                    $this->prependRoutesByFile($dir . '/' . $entry);
            }
            closedir($handle);
        }
    }

    /**
     * Resolves and appends the project-level route override file.
     *
     * @return void
     * @throws \Exception If the route file cannot be resolved to an existing file.
     */
    private function loadAdditionalRoutes(): void {
        if (!is_file($this->routeFile) && !is_file(Helper::getNormDocRoot() . VINOU_CONFIG_DIR . $this->routeFile))
            throw new \Exception('Route configuration file could not be solved');

        $absRouteFile = str_starts_with($this->routeFile, '/')
            ? $this->routeFile
            : Helper::getNormDocRoot() . VINOU_CONFIG_DIR . $this->routeFile;

        $this->appendRoutesByFile($absRouteFile);
    }

    /**
     * Appends a single route file from an absolute path, bypassing docroot resolution.
     *
     * Useful for registering vendor-internal routes (e.g. admin panel) that live
     * outside the project docroot and should not be merged with default routes.
     *
     * @param string $file  Absolute path to a .yml route file.
     * @return void
     */
    public function loadRouteFile(string $file): void {
        $this->appendRoutesByFile($file);
    }

    /**
     * Merges a route file on top of the current configuration (higher priority).
     *
     * Routes with 'extend: true' are deep-merged with the existing entry for
     * the same pattern via array_replace_recursive instead of replacing it.
     *
     * @param string $file  Absolute path to a .yml route file.
     * @return void
     */
    private function appendRoutesByFile(string $file): void {
        if (!is_file($file))
            return;

        $newRoutes = Yaml::parseFile($file);

        foreach ($newRoutes as $pattern => &$options) {
            if (isset($options['extend']) && $options['extend'] === true && isset($this->configuration[$pattern])) {
                unset($options['extend']);
                $options = array_replace_recursive($this->configuration[$pattern], $options);
            }
        }

        $this->configuration = array_merge($this->configuration, $newRoutes);
    }

    /**
     * Merges a route file underneath the current configuration (lower priority).
     *
     * Existing configuration (theme routes) always wins over entries in $file.
     *
     * @param string $file  Absolute path to a .yml route file.
     * @return void
     */
    private function prependRoutesByFile(string $file): void {
        if (!is_file($file))
            return;

        $this->configuration = array_merge(Yaml::parseFile($file), $this->configuration);
    }

    /**
     * Registers all routes in $configuration with the underlying router.
     *
     * Supports three route types: 'redirect', 'namespace' (grouped routes),
     * 'sitemap', and the default 'page' type. Page routes run additionalContent
     * (minus any excludeContent keys), then dataProcessing, then render.
     *
     * @param array<string, mixed>|null $configuration  Route map to register; defaults to $this->configuration.
     * @return void
     */
    private function generateRoutes(?array $configuration = null): void {
        if (is_null($configuration))
            $configuration = $this->configuration;

        foreach ($configuration as $pattern => $options) {
            if ($pattern[0] != '/')
                $pattern = '/' . $pattern;

            $options['pattern'] = $pattern;

            if (!isset($options['type']))
                $options['type'] = 'page';

            $method = isset($options['method']) ? $options['method'] : 'get';

            switch ($options['type']) {
                case 'redirect':
                    $this->router->{$method}($pattern, function() use ($options) {
                        $this->render->redirect($options['redirect']);
                    });
                    break;

                case 'namespace':
                    $this->router->mount($pattern, function() use ($options) {
                        if (isset($options['defaults'])) {
                            $defaults = $options['defaults'];
                            foreach ($options['routes'] as &$routeOptions) {
                                foreach ($defaults as $key => $value) {
                                    if (!isset($routeOptions[$key]))
                                        $routeOptions[$key] = $value;
                                }
                            }
                        }
                        $this->generateRoutes($options['routes']);
                    });
                    break;

                case 'sitemap':
                    $this->router->{$method}($pattern, function() use ($configuration) {
                        $this->render->processors['sitemap']->renderSitemapXML($configuration);
                    });
                    break;

                default:
                    // Page
                    $this->router->{$method}($pattern, function() use ($options) {
                        $template = $this->detectForTemplate($options);
                        if (isset($options['contentFunc']))
                            $this->render->{$options['contentFunc']}($options);

                        $content = $this->additionalContent;
                        if (!empty($content)) {
                            if (isset($options['excludeContent'])) {
                                if ($options['excludeContent'] === true) {
                                    $content = [];
                                } elseif (is_array($options['excludeContent'])) {
                                    foreach ($options['excludeContent'] as $key) {
                                        unset($content[$key]);
                                    }
                                }
                            }
                            if (!empty($content))
                                $this->render->dataProcessing($content);
                        }

                        if (isset($options['dataProcessing']))
                            $this->render->dataProcessing($options['dataProcessing'], func_get_args());
                        if (isset($options['postProcessing']))
                            $this->render->postProcessing($options['postProcessing'], func_get_args());

                        $this->render->loadUrlParams(func_get_args(), $options);
                        $this->render->renderPage($template, $options);
                    });
                    break;
            }
        }
    }

    /**
     * Resolves the Twig template name for a route.
     *
     * Returns the explicit 'template' value when set. Falls back to deriving a
     * .twig filename from a legacy .html request URI.
     *
     * @param array<string, mixed> $options  Route options array.
     * @return string|null  Template filename, or null if none could be detected.
     */
    private function detectForTemplate(array $options): ?string {
        if (isset($options['template']))
            return $options['template'];

        if (strpos($_SERVER['REQUEST_URI'], '.html'))
            return str_replace('.html', '.twig', $_SERVER['REQUEST_URI']);

        return null;
    }
}
