<?php
namespace Vinou\SiteBuilder\Tools;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\PublicApi;
use \Vinou\ApiConnector\Services\ServiceLocator;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Vinou\SiteBuilder\Processors\ApiAwareInterface;
use \Vinou\SiteBuilder\Processors\ProcessorInterface;
use \Twig\Loader\FilesystemLoader;
use \Twig\Environment;
use \Twig\Extension\DebugExtension;

/**
 * Core rendering engine for SiteBuilder pages and AJAX responses.
 *
 * Responsibilities:
 *   - API connection bootstrap (connect())
 *   - DataProcessing pipeline execution (dataProcessing())
 *   - Twig environment initialisation, delegating filter registration to FilterRegistry
 *   - Page rendering (renderPage()) and JSON responses (sendJSON / sendJSONError)
 *   - Template storage management (loadDefaultStorages, addTemplateStorages)
 *
 * Image utilities are in ImageService; Twig filters are in FilterRegistry.
 */
class Render implements RendererInterface {

    /** @var string Webroot-relative base path for default template directories. */
    protected string $templateRootPath = 'Resources/';

    /** @var list<string> Sub-directory names appended to $templateRootPath when scanning. */
    protected array $templateDirectories = ['Templates/', 'Partials/', 'Layouts/'];

    /** @var string Default layout template filename. */
    protected string $layout = 'default.twig';

    /** @var list<string> Supported language codes for path-based language detection. */
    protected array $languages = ['en', 'de'];

    /** @var string Default language code when no language segment is found in the URL. */
    protected string $defaultlang = 'en';

    /** @var list<string>|null URL path segments; currently unused (reserved for multi-language routing). */
    protected ?array $pathsegments = null;

    /** @var array<string, mixed>|null Extra options passed to the constructor (e.g. language override). */
    protected ?array $options = null;

    /** @var array<string, mixed>|null Merged system config block from the settings service. */
    protected ?array $config = null;

    /**
     * Full merged settings array, loaded at the start of each renderPage() call.
     *
     * @var array<string, mixed>
     */
    protected array $settings = [];

    /** @var object|null Settings service from the service locator. */
    protected ?object $settingsService = null;

    /**
     * Registered processor instances, keyed by processor name.
     *
     * @var array<string, object>
     */
    public array $processors = [];

    /**
     * Ordered list of absolute paths that Twig searches for templates.
     *
     * @var list<string>
     */
    public array $templateStorages = [];

    /**
     * Data passed to Twig on each render; acts as the template context.
     *
     * @var array<string, mixed>
     */
    public array $renderArr = [];

    /** @var array<string, mixed>|null Localization data from loadLocalization() (tastes, units, …). */
    public ?array $translation = null;

    /** @var array<int|string, string> Flat wine-region map keyed by region ID. */
    public array $regions = [];

    /** @var array<string, string> Country map keyed by ISO code. */
    public array $countries = [];

    /** @var Api|PublicApi|null Active API instance, set by connect(). */
    public Api|PublicApi|null $api = null;

    /** @var bool True when VINOU_LOCAL is defined and truthy. */
    public bool $local = false;

    /**
     * @param array<string, mixed>|null $options  Optional runtime options (e.g. ['language' => 'de']).
     */
    public function __construct(?array $options = null) {
        if (!is_null($options))
            $this->options = $options;

        if (defined('PAGE_LANGUAGES'))
            $this->languages = unserialize(PAGE_LANGUAGES);

        if (defined('PAGE_DEFAULTLANG'))
            $this->defaultlang = PAGE_DEFAULTLANG;

        if (defined('VINOU_LOCAL'))
            $this->local = VINOU_LOCAL;

        $this->settingsService = ServiceLocator::get('Settings');
        $this->renderArr['local'] = $this->local;
        $this->defaultPageData();
    }

    /**
     * Stores the shop settings config block for use in permission checks.
     *
     * @param array<string, mixed>|null $config  Shop settings array.
     * @return void
     */
    public function setConfig(?array $config = null): void {
        $this->config = $config;
    }

    /**
     * Stores a settings snapshot (legacy setter, rarely needed externally).
     *
     * @param array<string, mixed>|null $settings
     * @return void
     */
    public function setSettings(?array $settings = null): void {
        $this->settings = $settings ?? [];
    }

    /**
     * Populates renderArr with request-level data available on every page.
     *
     * Sets path, host, domain, date/time, basket UUID, card contents,
     * POST/GET params, language list, and the current language code.
     *
     * @return void
     */
    private function defaultPageData(): void {
        $this->renderArr['path']        = explode('?', $_SERVER['REQUEST_URI'])[0];
        $this->renderArr['request_uri'] = $_SERVER['REQUEST_URI'];
        $this->renderArr['backlink']    = $_SERVER['HTTP_REFERER'] ?? false;
        $this->renderArr['host']        = $_SERVER['HTTP_HOST'];
        $this->renderArr['domain']      = Helper::getCurrentHost();
        $this->renderArr['date']        = date('d.m.Y');
        $this->renderArr['year']        = date('Y');
        $this->renderArr['time']        = date('h:i');
        $this->renderArr['basketuuid']  = Session::getValue('basket');

        $items = Session::getValue('card');
        if (is_array($items) && !empty($items)) {
            $this->renderArr['card'] = [];
            foreach ($items as $item) {
                $object                   = $item['item'];
                $object['quantity']       = $item['quantity'];
                $object['basket_item_id'] = $item['id'];
                $this->renderArr['card'][$item['item_type'] . 's'][$item['item_id']] = $object;
            }
        }

        foreach ($_POST as $postKey => $postValue) {
            $this->renderArr['postParams'][$postKey] = $postValue;
        }
        foreach ($_GET as $getKey => $getValue) {
            $this->renderArr['getParams'][$getKey] = $getValue;
        }

        $this->renderArr['languages'] = $this->languages;
        $this->renderArr['protocol']  = Helper::fetchProtocol();

        if ($this->pathsegments && count($this->pathsegments) > 1 && in_array($this->pathsegments[0], $this->languages))
            $this->renderArr['language'] = $this->pathsegments[0];
        else
            $this->renderArr['language'] = $this->defaultlang;
    }

    /**
     * Initialises the API connection and loads localization data.
     *
     * In 'Public' mode a PublicApi is used (no authentication).
     * In all other modes a full Api is created; if the connection fails,
     * an error page is rendered and execution terminates via die().
     *
     * @return true
     */
    public function connect(): true {
        if (!defined('VINOU_MODE'))
            define('VINOU_MODE', 'Default');

        if (VINOU_MODE === 'Public') {
            $this->api = new PublicApi();
        } else {
            $this->api = new Api();

            if (!$this->api->connected) {
                $this->renderPage('error.twig', ['pageTitle' => 'No Connection']);
                die();
            }

            $this->api->initBasket();

            $this->translation = $this->api->loadLocalization();
            foreach ($this->translation['wineregions'] as $countryregions) {
                $this->regions = array_replace($this->regions, $countryregions);
            }
            $this->countries = $this->translation['countries'];

            $this->renderArr['regions']   = $this->regions;
            $this->renderArr['countries'] = $this->countries;
        }

        return true;
    }

    /**
     * Registers a processor instance under a named key.
     *
     * @param string $processor  Processor key (e.g. 'shop', 'mailer').
     * @param object $object     Processor instance.
     * @return void
     */
    public function loadProcessor(string $processor, ProcessorInterface $object): void {
        $this->processors[$processor] = $object;
    }

    /**
     * Executes the dataProcessing pipeline defined in route YAML.
     *
     * Each step in $options resolves a function call on the API or a named
     * processor, then stores the result (or a sub-key thereof) in renderArr.
     *
     * Short form: 'key' => 'apiFunction'
     * Long form:  'key' => ['function' => '...', 'processor' => '...', 'params' => [...], ...]
     *
     * Supported options: processor, class, params, postParams, getParams,
     * useData, useRouteData, key, forceLoadAll, loadOnlyFirst, stopProcessing.
     *
     * @param array<string, mixed>|null $options  Keyed processing steps from route YAML.
     * @param array<int, mixed>         $data     URL wildcard segments from the router.
     * @return void
     */
    public function dataProcessing(?array $options = null, array $data = []): void {
        if (!is_array($options))
            return;

        foreach ($options as $key => $option) {
            $functionData = $data;

            if (is_string($option)) {
                $function = $option;
                $result   = call_user_func_array([$this->api, $function], $functionData);
            } elseif (is_array($option) && isset($option['function'])) {
                $function = $option['function'];
                unset($option['function']);

                if (array_key_exists('useRouteData', $option) && !$option['useRouteData'])
                    $functionData = [];

                if (isset($option['params']))
                    $functionData = array_merge($functionData, $option['params']);

                if (isset($option['postParams']) && !empty($_POST)) {
                    $allowedKeys = explode(',', $option['postParams']);
                    foreach ($_POST as $postKey => $postValue) {
                        if (in_array($postKey, $allowedKeys)) {
                            if (isset($functionData[$postKey]) && is_array($functionData[$postKey]))
                                $functionData[$postKey] = array_merge($functionData[$postKey], $postValue);
                            else
                                $functionData[$postKey] = $postValue;
                        }
                    }
                }

                if (isset($option['getParams']) && !empty($_GET)) {
                    $allowedKeys = explode(',', $option['getParams']);
                    foreach ($_GET as $getKey => $getValue) {
                        if (in_array($getKey, $allowedKeys)) {
                            if (isset($functionData[$getKey]) && is_array($functionData[$getKey]))
                                $functionData[$getKey] = array_merge($functionData[$getKey], $getValue);
                            else
                                $functionData[$getKey] = $getValue;
                        }
                    }
                }

                if (isset($option['useData'])) {
                    if (is_array($option['useData']))
                        $dataToUse = $option['useData'];
                    elseif (strpos($option['useData'], ','))
                        $dataToUse = explode(',', $option['useData']);
                    else
                        $dataToUse = $option['useData'];

                    if (is_array($dataToUse)) {
                        foreach ($dataToUse as $dataKey) {
                            if (isset($this->renderArr[$dataKey]) && $this->renderArr[$dataKey]) {
                                if (isset($functionData[$dataKey]))
                                    $functionData[$dataKey] = array_merge($functionData[$dataKey], $this->renderArr[$dataKey]);
                                else
                                    $functionData[$dataKey] = $this->renderArr[$dataKey];
                            }
                        }
                    } else {
                        if (is_array($this->renderArr[$dataToUse]))
                            $functionData = array_merge($functionData, $this->renderArr[$dataToUse]);
                        else
                            array_push($functionData, $this->renderArr[$dataToUse]);
                    }
                }

                if (isset($option['class'])) {
                    if (isset($option['initApiOnConstruct']) && $option['initApiOnConstruct'])
                        $class = new $option['class']($this->api);
                    else {
                        $class = new $option['class'];
                        if ($class instanceof ApiAwareInterface)
                            $class->loadApi($this->api);
                    }
                } elseif (isset($option['processor'])) {
                    if (!isset($this->processors[$option['processor']]))
                        throw new \Exception('data processor does not exists');
                    $class = $this->processors[$option['processor']];
                } else {
                    $class = $this->api;
                }

                if (!method_exists($class, $function))
                    throw new \Exception('function ' . $function . ' does not exists in called procesor (' . get_class($class) . ')');

                $result = $class->{$function}($functionData);
            } else {
                throw new \Exception('dataProcessing for this route could not be solved');
            }

            $return   = $result;
            $selector = $option['key'] ?? $key;

            if (isset($result[$selector]))
                $return = $result[$selector];

            if (isset($option['forceLoadAll']) && $option['forceLoadAll'])
                $return = $result;

            if (isset($option['loadOnlyFirst']) && $option['loadOnlyFirst'] && is_array($return))
                $return = array_shift($return);

            $this->renderArr[$key] = $return;

            if (isset($result['clusters']))
                $this->renderArr['clusters'] = $result['clusters'];

            if (isset($option['stopProcessing']) && $option['stopProcessing'] && !$result)
                break;
        }
    }

    /**
     * Builds and returns a configured Twig Environment.
     *
     * If no template storages have been registered yet, falls back to the
     * SiteBuilder's own Resources/ directory. Delegates all filter registrations
     * to FilterRegistry. Extensions: twig/extensions (Intl, Array), DebugExtension,
     * and Vinou translations.
     *
     * @param array<string, mixed>|null $config  Optional overrides for cache path and debug flag.
     * @return Environment
     */
    private function initTwig(?array $config = null): Environment {
        // Always append package templates as lowest-priority fallback so that
        // built-in templates (Admin panel, 404, …) are found even after
        // loadDefaultStorages() and addTemplateStorages() have run.
        $packageResourcePath = str_replace('Classes/Tools', 'Resources', Helper::getClassPath(get_class($this)));
        foreach ($this->templateDirectories as $dir) {
            $packageDir = $packageResourcePath . '/' . $dir;
            if (is_dir($packageDir) && !in_array($packageDir, $this->templateStorages))
                $this->templateStorages[] = $packageDir;
        }

        $loader = new FilesystemLoader($this->templateStorages);

        $twigSettings = [
            'cache' => defined('VINOU_CACHE') ? VINOU_CACHE : Helper::getNormDocRoot() . 'Cache/Twig',
            'debug' => defined('VINOU_DEBUG') ? VINOU_DEBUG : false,
        ];
        if (isset($config['cache'])) $twigSettings['cache'] = $config['cache'];
        if (isset($config['debug'])) $twigSettings['debug'] = $config['debug'];

        $twig = new Environment($loader, $twigSettings);

        $twig->addExtension(new DebugExtension());
        $twig->addExtension(new \Vinou\Translations\TwigExtension($this->options['language'] ?? 'de'));

        $registry = new FilterRegistry($this->api, $this->translation ?? [], $this->regions, $this->settings);
        $registry->registerAll($twig);

        return $twig;
    }

    /**
     * Parses a local Markdown or plain-text file and stores the result in renderArr['content'].
     *
     * @param string $mdfile  Webroot-relative path to the file.
     * @return void
     */
    private function addMDContent(string $mdfile): void {
        $Parsedown = new \Parsedown();
        $absFile   = Helper::getNormDocRoot() . $mdfile;
        $content   = file_get_contents($absFile);
        if (strpos($absFile, '.md'))
            $this->renderArr['content'] = $Parsedown->text($content);
        else
            $this->renderArr['content'] = $content;
    }

    /**
     * Maps URL wildcard arguments to named keys in renderArr['urlParams'].
     *
     * When 'urlKeys' is defined in $options, the positional $arguments are
     * combined with the key list; missing entries on either side are padded
     * with null. Without 'urlKeys', $arguments is stored as a plain list.
     *
     * @param list<mixed>               $arguments  URL wildcard values from the router callback.
     * @param array<string, mixed>|null $options    Route options that may contain 'urlKeys'.
     * @return void
     */
    public function loadUrlParams(array $arguments, ?array $options = null): void {
        if (isset($options['urlKeys'])) {
            if (count($options['urlKeys']) > count($arguments))
                $arguments = array_merge($arguments, array_fill(0, count($options['urlKeys']) - count($arguments), null));

            if (count($options['urlKeys']) < count($arguments))
                $options['urlKeys'] = array_merge($options['urlKeys'], array_fill(0, count($arguments) - count($options['urlKeys']), null));

            $this->renderArr['urlParams'] = array_combine($options['urlKeys'], $arguments);
        } else {
            $this->renderArr['urlParams'] = $arguments;
        }
    }

    /**
     * Renders a Twig template and sends the output to the client.
     *
     * Loads the full settings snapshot, builds the Twig environment, applies
     * route options (including access control via 'public' flag), and echoes
     * the rendered template. Always terminates via exit().
     *
     * @param string                    $template  Twig template filename (default 'Default.twig').
     * @param array<string, mixed>|null $options   Route options merged into the template context.
     * @return never
     */
    public function renderPage(string $template = 'Default.twig', ?array $options = null): never {
        $this->settings = $this->settingsService->getAll();

        $view     = $this->initTwig($options['twig'] ?? null);
        $template = $view->loadTemplate($template);

        if (!is_null($options)) {
            if (isset($options['public']) && !$options['public'] && !Session::getValue('client'))
                $this->forbidden();
            $this->renderOptions($options);
        }

        echo $template->render($this->renderArr);
        exit();
    }

    /**
     * Merges route option values into the template context (renderArr).
     *
     * The special key 'content' triggers Markdown parsing via addMDContent().
     * A plain string $options value is stored as 'pageTitle'.
     *
     * @param array<string, mixed>|string $options  Route options array or a page title string.
     * @return void
     */
    public function renderOptions(array|string $options): void {
        if (is_array($options)) {
            foreach ($options as $name => $value) {
                if ($name === 'content')
                    $this->renderArr[$name] = $this->addMDContent($value);
                else
                    $this->renderArr[$name] = $value;
            }
        } elseif (is_string($options)) {
            $this->renderArr['pageTitle'] = $options;
        }
    }

    /**
     * Redirects to $target, choosing between external and internal redirect.
     *
     * @param string $target  Absolute URL (starts with 'http') or internal path.
     * @return void
     */
    public function redirect(string $target): void {
        if (strncmp($target, 'http', 4) === 0)
            Redirect::external($target);
        else
            Redirect::internal($target);
    }

    /**
     * Redirects to the configured permission redirect URL when access is denied.
     *
     * No-op when system.permissionRedirect is not set.
     *
     * @return void
     */
    public function forbidden(): void {
        if (isset($this->config['permissionRedirect']))
            Redirect::internal($this->config['permissionRedirect']);
    }

    /**
     * Overrides the webroot-relative base path for default template directories.
     *
     * @param string $path  New base path (e.g. 'public/Resources/').
     * @return void
     */
    public function setTemplateRootPath(string $path): void {
        $this->templateRootPath = $path;
    }

    /**
     * Replaces the list of template sub-directory names.
     *
     * @param list<string> $directories  Sub-directory names (e.g. ['Templates/', 'Partials/']).
     * @return void
     */
    public function setTemplateDirectories(array $directories): void {
        $this->templateDirectories = $directories;
    }

    /**
     * Appends the default webroot template directories to the storage list.
     *
     * Called once per request before theme directories are added. Skips
     * directories that are already registered or do not exist.
     *
     * @return void
     */
    public function loadDefaultStorages(): void {
        foreach ($this->templateDirectories as $dir) {
            $resourceDir = Helper::getNormDocRoot() . $this->templateRootPath . $dir;
            if (is_dir($resourceDir) && !in_array($resourceDir, $this->templateStorages))
                $this->templateStorages[] = $resourceDir;
        }
    }

    /**
     * Appends template directories from a root directory to the storage list.
     *
     * When $folders is empty, $rootDir itself is added. Otherwise each
     * $rootDir/$folder is added if it exists.
     *
     * @param string       $rootDir  Absolute or webroot-relative root directory path.
     * @param list<string> $folders  Optional sub-folder names to add.
     * @return void
     */
    public function addTemplateStorages(string $rootDir, array $folders = []): void {
        if (!str_starts_with($rootDir, '/'))
            $rootDir = Helper::getNormDocRoot() . $rootDir;

        if (empty($folders)) {
            if (is_dir($rootDir))
                $this->templateStorages[] = $rootDir;
        } else {
            foreach ($folders as $dir) {
                if (is_dir($rootDir . $dir))
                    $this->templateStorages[] = $rootDir . $dir;
            }
        }
    }

    /**
     * Sets the list of supported language codes for URL-based language detection.
     *
     * @param list<string> $keys  Language codes (e.g. ['de', 'en', 'fr']).
     * @return void
     */
    public function setLanguages(array $keys): void {
        $this->languages = $keys;
    }

    /**
     * Sets the default language code used when no language segment is in the URL.
     *
     * @param string $key  Language code (e.g. 'de').
     * @return void
     */
    public function setDefaultLanguage(string $key): void {
        $this->defaultlang = $key;
    }

    /**
     * Sends a JSON success response with HTTP 200 and terminates.
     *
     * @param mixed $data  Data to encode as JSON.
     * @return never
     */
    public static function sendJSON(mixed $data): never {
        header('Cache-Control: no-cache, must-revalidate');
        header('Content-type: application/json');
        header('HTTP/1.1 200 OK');
        echo json_encode($data);
        exit();
    }

    /**
     * Sends a JSON error response with the given HTTP status code and terminates.
     *
     * Supported codes: 400, 403, 409. Any other value defaults to 500.
     *
     * @param mixed $data      Error data to encode as JSON.
     * @param int   $httpCode  HTTP status code (default 500).
     * @return never
     */
    public static function sendJSONError(mixed $data, int $httpCode = 500): never {
        header('Cache-Control: no-cache, must-revalidate');
        header('Content-type: application/json');

        switch ($httpCode) {
            case 400: header('HTTP/1.0 400 Not Found'); break;
            case 403: header('HTTP/1.0 403 Unauthorized'); break;
            case 409: header('HTTP/1.0 409 Bad Request'); break;
            default:  header('HTTP/1.0 500 Internal Server Error'); break;
        }

        echo json_encode($data);
        exit();
    }
}
