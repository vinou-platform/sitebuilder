<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Services\ServiceLocator;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Vinou\SiteBuilder\Router\DynamicRoutes;
use \Symfony\Component\Yaml\Yaml;

/**
 * Processor for the SiteBuilder admin panel.
 *
 * Handles authentication (password from system.password in settings.yml),
 * session management, and data aggregation for all admin panel sections:
 * Settings, Routes, Mail, System (cache clearing), and Environment checks.
 *
 * Registered under the key 'admin' by Site::loadAdminPanel() when
 * system.password is set in the project's settings.yml.
 */
class Admin implements ProcessorInterface {

    private const SESSION_KEY = 'vinou_admin_auth';

    /** @var array<string, mixed> Shared data storage. */
    public array $data = [];

    /** @var object Settings service from the service locator. */
    private object $settingsService;

    /** @var DynamicRoutes Route configuration for the Routes panel section. */
    private DynamicRoutes $routeConfig;

    /**
     * @param DynamicRoutes $routeConfig  Reference to the active router configuration.
     */
    public function __construct(DynamicRoutes &$routeConfig) {
        $this->settingsService = ServiceLocator::get('Settings');
        $this->routeConfig     = $routeConfig;
    }

    /**
     * Main entry point called from the admin route handler.
     *
     * Handles POST login and GET logout. Authenticated GET requests are
     * redirected to /system/settings (the default section).
     *
     * @return array<string, mixed>  Template data with 'authenticated' flag and optional 'error'.
     */
    public function init(): array {
        $system = $this->settingsService->get('system') ?? [];

        if (!isset($system['password']))
            return ['authenticated' => false, 'version' => $this->getVersion(), 'error' => 'Kein Admin-Passwort in system.password gesetzt.'];

        // Handle POST login
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            if ($_POST['password'] === $system['password']) {
                Session::setValue(self::SESSION_KEY, true);
                Redirect::internal('/system/settings');
            }
            return ['authenticated' => false, 'version' => $this->getVersion(), 'error' => 'Falsches Passwort.'];
        }

        if (!$this->isAuthenticated())
            return ['authenticated' => false, 'version' => $this->getVersion()];

        $action = $_GET['action'] ?? null;

        if ($action === 'logout') {
            Session::deleteValue(self::SESSION_KEY);
            Redirect::internal('/system');
        }

        Redirect::internal('/system/settings');
    }

    /**
     * Entry point for /system/{name} — serves Panel.twig with section data.
     *
     * Handles both direct browser access and HTMX hx-select navigation.
     * Panel.twig renders the section inline; HTMX extracts #section-content
     * from the full-page response via hx-select.
     *
     * @param array<int|string, mixed> $params  First element is the section slug.
     * @return array<string, mixed>
     */
    public function page(array $params = []): array {
        if (!$this->isAuthenticated())
            return ['authenticated' => false];

        $name = $params[0] ?? 'settings';
        $valid = ['settings', 'routes', 'mail', 'system', 'environment', 'redirects'];
        if (!in_array($name, $valid, true))
            $name = 'settings';

        $message = null;
        if ($name === 'system' && ($_GET['action'] ?? '') === 'clearCache') {
            $message = $this->clearCache($_GET['type'] ?? '');
        }

        $sectionData = match ($name) {
            'settings'    => ['sectionName' => $name, 'settings' => $this->getSettings(), 'projectPaths' => $this->getProjectSettingsPaths(), 'flash' => $this->popFlash()],
            'routes'      => ['sectionName' => $name, 'routes'      => $this->getRoutes()],
            'mail'        => ['sectionName' => $name, 'mailConfig'  => $this->getMailConfig()],
            'system'      => ['sectionName' => $name, 'message'     => $message],
            'environment' => ['sectionName' => $name, 'environment' => $this->checkEnvironment()],
            'redirects'   => ['sectionName' => $name, 'redirects'   => $this->getRedirects(), 'flash' => $this->popFlash()],
            default       => ['sectionName' => $name],
        };

        return array_merge(['authenticated' => true, 'adminTab' => $name], $sectionData);
    }

    /**
     * Saves a single setting value to config/settings.yml at a dot-notation path.
     *
     * Values are parsed as YAML scalars (42 → int, true → bool, "foo" → string).
     * After saving, redirects to /system/settings so the page reloads with fresh
     * settings from disk (avoids in-memory cache showing stale data).
     *
     * @return array<string, mixed>
     */
    public function saveSetting(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Redirect: /system');
                exit;
            }
            return ['authenticated' => false];
        }

        $key = trim($_POST['key'] ?? '');
        $raw = $_POST['value'] ?? '';

        if ($key === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Kein Schlüssel angegeben.']);
        } elseif (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
        } else {
            try {
                $value = Yaml::parse($raw);
            } catch (\Exception $e) {
                $value = $raw;
            }

            $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];

            $this->setNestedValue($current, $key, $value);
            file_put_contents($configFile, Yaml::dump($current, 10, 2));

            Session::setValue('admin_flash', ['type' => 'success', 'message' => "'{$key}' gespeichert."]);
        }

        header('HX-Redirect: /system/settings');
        exit;
    }

    /**
     * Removes a key at a dot-notation path from config/settings.yml.
     *
     * @return array<string, mixed>
     */
    public function deleteSetting(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Redirect: /system');
                exit;
            }
            return ['authenticated' => false];
        }

        $path = trim($_POST['key'] ?? '');

        if ($path === '' || !defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültiger Schlüssel.']);
        } else {
            $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];

            $this->unsetNestedValue($current, $path);
            file_put_contents($configFile, Yaml::dump($current, 10, 2));

            Session::setValue('admin_flash', ['type' => 'success', 'message' => "'{$path}' gelöscht."]);
        }

        header('HX-Redirect: /system/settings');
        exit;
    }

    /**
     * Returns all dot-notation paths present in config/settings.yml (project layer only).
     * Used by the template to determine which settings are deletable.
     *
     * @return list<string>
     */
    private function getProjectSettingsPaths(): array {
        if (!defined('VINOU_CONFIG_DIR'))
            return [];

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        if (!is_file($configFile))
            return [];

        return $this->flattenKeys(Yaml::parseFile($configFile) ?? []);
    }

    /**
     * Recursively collects all dot-notation paths (leaf and intermediate) from a nested array.
     *
     * @param  array<mixed, mixed> $array
     * @return list<string>
     */
    private function flattenKeys(array $array, string $prefix = ''): array {
        $paths = [];
        foreach ($array as $key => $value) {
            $path    = $prefix !== '' ? $prefix . '.' . $key : (string)$key;
            $paths[] = $path;
            if (is_array($value))
                $paths = array_merge($paths, $this->flattenKeys($value, $path));
        }
        return $paths;
    }

    /**
     * Removes the value at a dot-notation path from a nested array.
     */
    private function unsetNestedValue(array &$array, string $path): void {
        $keys    = explode('.', $path);
        $lastKey = array_pop($keys);
        $lastKey = ctype_digit($lastKey) ? (int)$lastKey : $lastKey;
        $current = &$array;
        foreach ($keys as $key) {
            $key = ctype_digit($key) ? (int)$key : $key;
            if (!isset($current[$key]) || !is_array($current[$key]))
                return;
            $current = &$current[$key];
        }
        unset($current[$lastKey]);
    }

    /**
     * Appends a new element to an array or adds a key to an object in config/settings.yml.
     *
     * POST fields: path (dot-notation to parent), key (empty = array-append), value (YAML scalar).
     *
     * @return array<string, mixed>
     */
    public function addSetting(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Redirect: /system');
                exit;
            }
            return ['authenticated' => false];
        }

        $path = trim($_POST['path'] ?? '');
        $key  = trim($_POST['key'] ?? '');
        $raw  = $_POST['value'] ?? '';

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
        } else {
            try {
                $value = Yaml::parse($raw);
            } catch (\Exception $e) {
                $value = $raw;
            }

            $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];

            $this->appendToPath($current, $path, $key, $value);
            file_put_contents($configFile, Yaml::dump($current, 10, 2));

            $label = $key !== '' ? "'{$path}.{$key}'" : "Element in '{$path}'";
            Session::setValue('admin_flash', ['type' => 'success', 'message' => "{$label} hinzugefügt."]);
        }

        header('HX-Redirect: /system/settings');
        exit;
    }

    /**
     * Appends a value to a sequential array or sets a key on an object at a dot-notation path.
     * An empty $key triggers array-append ([]= operator); a non-empty $key sets $parent[$key].
     */
    private function appendToPath(array &$array, string $path, string $key, mixed $value): void {
        $current = &$array;
        if ($path !== '') {
            foreach (explode('.', $path) as $segment) {
                $segment = ctype_digit($segment) ? (int)$segment : $segment;
                if (!isset($current[$segment]) || !is_array($current[$segment]))
                    $current[$segment] = [];
                $current = &$current[$segment];
            }
        }
        if ($key === '') {
            $current[] = $value;
        } else {
            $current[$key] = $value;
        }
    }

    /**
     * Reads and clears the one-time flash message from the session.
     *
     * @return array{type: string, message: string}|null
     */
    private function popFlash(): ?array {
        $flash = Session::getValue('admin_flash');
        if ($flash !== null)
            Session::deleteValue('admin_flash');
        return $flash ?: null;
    }

    /**
     * Sets a value at a dot-notation path within a nested array.
     * Numeric path segments are cast to int for sequential arrays.
     *
     * @param array<mixed, mixed> $array  Target array (modified in place).
     * @param string              $path   Dot-separated key path, e.g. 'shop.seo.titleAdd'.
     * @param mixed               $value  Value to set.
     */
    private function setNestedValue(array &$array, string $path, mixed $value): void {
        $keys    = explode('.', $path);
        $current = &$array;
        foreach ($keys as $key) {
            $key = ctype_digit($key) ? (int)$key : $key;
            if (!isset($current[$key]) || !is_array($current[$key]))
                $current[$key] = [];
            $current = &$current[$key];
        }
        $current = $value;
    }

    /**
     * Returns true if the current session has a valid admin token.
     */
    private function isAuthenticated(): bool {
        return Session::getValue(self::SESSION_KEY) === true;
    }

    /**
     * Returns the installed version string of vinou/site-builder.
     */
    private function getVersion(): string {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                return \Composer\InstalledVersions::getPrettyVersion('vinou/site-builder') ?? 'dev';
            } catch (\Exception) {
                return 'dev';
            }
        }
        return 'dev';
    }

    /**
     * Returns the full merged settings array from the settings service.
     *
     * @return array<string, mixed>
     */
    private function getSettings(): array {
        $settings = $this->settingsService->getAll() ?? [];
        unset($settings['auth']); // runtime copy of vinou credentials set by Api connector, not a config key
        return $settings;
    }

    /**
     * Returns the full route configuration from DynamicRoutes.
     *
     * @return array<string, mixed>
     */
    private function getRoutes(): array {
        return $this->routeConfig->configuration;
    }

    /**
     * Reads mail.yml and augments it with an SMTP connectivity check.
     *
     * Returns an empty array with an 'error' key if mail.yml is missing or
     * VINOU_CONFIG_DIR is not defined.
     *
     * @return array<string, mixed>
     */
    private function getMailConfig(): array {
        if (!defined('VINOU_CONFIG_DIR'))
            return ['error' => 'VINOU_CONFIG_DIR nicht definiert'];

        $file = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'mail.yml';
        if (!is_file($file))
            return ['error' => 'mail.yml nicht gefunden unter ' . $file];

        $config = Yaml::parseFile($file);

        if (isset($config['smtp'])) {
            $smtp              = $config['smtp'];
            $config['smtp']['reachable'] = $this->checkSmtp(
                $smtp['host'] ?? '',
                (int)($smtp['port'] ?? 25)
            );
        }

        return $config;
    }

    /**
     * Tests TCP reachability of an SMTP host and port within a 3-second timeout.
     *
     * @param string $host  SMTP hostname.
     * @param int    $port  SMTP port.
     * @return bool  True if a connection could be established.
     */
    private function checkSmtp(string $host, int $port): bool {
        if (empty($host))
            return false;

        $connection = @fsockopen($host, $port, $errno, $errstr, 3);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Checks key PHP extensions and system capabilities required by SiteBuilder.
     *
     * @return array<string, array{available: bool, label: string, detail: string}>
     */
    private function checkEnvironment(): array {
        $cacheRoot = Helper::getNormDocRoot() . 'Cache/';

        $checks = [
            'php' => [
                'label'     => 'PHP Version',
                'available' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'detail'    => PHP_VERSION,
            ],
            'curl' => [
                'label'     => 'cURL',
                'available' => extension_loaded('curl'),
                'detail'    => extension_loaded('curl') ? curl_version()['version'] : 'nicht verfügbar',
            ],
            'gd' => [
                'label'     => 'GD Library',
                'available' => extension_loaded('gd'),
                'detail'    => extension_loaded('gd') ? (gd_info()['GD Version'] ?? 'vorhanden') : 'nicht verfügbar',
            ],
            'imagick' => [
                'label'     => 'ImageMagick (Imagick)',
                'available' => extension_loaded('imagick'),
                'detail'    => extension_loaded('imagick') ? (new \Imagick())->getVersion()['versionString'] ?? 'vorhanden' : 'nicht verfügbar',
            ],
            'intl' => [
                'label'     => 'Intl',
                'available' => extension_loaded('intl'),
                'detail'    => extension_loaded('intl') ? INTL_ICU_VERSION : 'nicht verfügbar',
            ],
            'mbstring' => [
                'label'     => 'Mbstring',
                'available' => extension_loaded('mbstring'),
                'detail'    => extension_loaded('mbstring') ? 'vorhanden' : 'nicht verfügbar',
            ],
            'allow_url_fopen' => [
                'label'     => 'allow_url_fopen',
                'available' => (bool)ini_get('allow_url_fopen'),
                'detail'    => ini_get('allow_url_fopen') ? 'aktiv' : 'deaktiviert',
            ],
            'cache_images' => [
                'label'     => 'Cache/Images schreibbar',
                'available' => is_writable($cacheRoot . 'Images'),
                'detail'    => is_dir($cacheRoot . 'Images') ? ($this->isWritable($cacheRoot . 'Images') ? 'schreibbar' : 'nicht schreibbar') : 'Ordner fehlt',
            ],
            'cache_twig' => [
                'label'     => 'Cache/Twig schreibbar',
                'available' => is_writable($cacheRoot . 'Twig'),
                'detail'    => is_dir($cacheRoot . 'Twig') ? ($this->isWritable($cacheRoot . 'Twig') ? 'schreibbar' : 'nicht schreibbar') : 'Ordner fehlt',
            ],
        ];

        return $checks;
    }

    /**
     * Deletes all files inside a named cache subdirectory of public/Cache/.
     *
     * Allowed types: Images, Instagram, PDF. The directory itself is preserved.
     *
     * @param string $type  Cache folder name (case-sensitive).
     * @return string  Human-readable result message.
     */
    private function clearCache(string $type): string {
        $allowed = ['Images', 'Instagram', 'PDF'];

        if (!in_array($type, $allowed, true))
            return 'Unbekannter Cache-Typ: ' . htmlspecialchars($type);

        $dir = Helper::getNormDocRoot() . 'Cache/' . $type;

        if (!is_dir($dir))
            return $type . ': Ordner existiert nicht.';

        $count = 0;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            if ($entry->isFile()) {
                unlink($entry->getPathname());
                $count++;
            }
        }

        return $type . ' Cache geleert (' . $count . ' ' . ($count === 1 ? 'Datei' : 'Dateien') . ' gelöscht).';
    }

    /**
     * Returns true if the given directory is writable.
     *
     * @param string $dir  Absolute path to directory.
     */
    private function isWritable(string $dir): bool {
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Returns all redirects from config/redirects.yml.
     *
     * Each entry is an associative array with keys: source, redirect, method.
     *
     * @return list<array{source: string, redirect: string, method: string}>
     */
    private function getRedirects(): array {
        if (!defined('VINOU_CONFIG_DIR'))
            return [];

        $file = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'redirects.yml';
        if (!is_file($file))
            return [];

        $raw = Yaml::parseFile($file) ?? [];
        $result = [];
        foreach ($raw as $source => $config) {
            if (!is_array($config) || ($config['type'] ?? '') !== 'redirect')
                continue;
            $result[] = [
                'source'   => (string)$source,
                'redirect' => $config['redirect'] ?? '',
                'method'   => $config['method'] ?? 'get',
            ];
        }
        return $result;
    }

    /**
     * Saves redirects array back to config/redirects.yml.
     *
     * @param list<array{source: string, redirect: string, method: string}> $redirects
     */
    private function saveRedirectsFile(array $redirects): void {
        $out = [];
        foreach ($redirects as $r) {
            $source = trim($r['source'], '/');
            if ($source === '' || ($r['redirect'] ?? '') === '')
                continue;
            $out[$source] = [
                'type'     => 'redirect',
                'redirect' => $r['redirect'],
                'method'   => $r['method'] ?? 'get',
            ];
        }

        $file = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'redirects.yml';
        file_put_contents($file, empty($out) ? '' : Yaml::dump($out, 3, 2));
    }

    /**
     * Adds or updates a redirect in config/redirects.yml.
     *
     * POST fields: source (URL path without leading slash), redirect (target URL), method (get|all|post).
     *
     * @return array<string, mixed>
     */
    public function saveRedirect(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Redirect: /system');
                exit;
            }
            return ['authenticated' => false];
        }

        $source         = trim($_POST['source'] ?? '', '/');
        $originalSource = trim($_POST['original_source'] ?? '', '/');
        $target         = trim($_POST['redirect'] ?? '');
        $method         = in_array($_POST['method'] ?? '', ['get', 'post', 'all'], true) ? $_POST['method'] : 'get';

        if ($source === '' || $target === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Quell-Pfad und Ziel-URL sind erforderlich.']);
            header('HX-Redirect: /system/redirects');
            exit;
        }

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
            header('HX-Redirect: /system/redirects');
            exit;
        }

        $current = $this->getRedirects();

        // Remove old entry when source path changes (rename)
        if ($originalSource !== '' && $originalSource !== $source)
            $current = array_values(array_filter($current, fn($r) => $r['source'] !== $originalSource));

        // Update existing or append
        $found = false;
        foreach ($current as &$r) {
            if ($r['source'] === $source) {
                $r['redirect'] = $target;
                $r['method']   = $method;
                $found = true;
                break;
            }
        }
        unset($r);
        if (!$found)
            $current[] = ['source' => $source, 'redirect' => $target, 'method' => $method];

        $this->saveRedirectsFile($current);
        Session::setValue('admin_flash', ['type' => 'success', 'message' => "Redirect '/{$source}' gespeichert."]);

        header('HX-Redirect: /system/redirects');
        exit;
    }

    /**
     * Deletes a redirect from config/redirects.yml by its source path.
     *
     * POST field: source (URL path without leading slash).
     *
     * @return array<string, mixed>
     */
    public function deleteRedirect(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Redirect: /system');
                exit;
            }
            return ['authenticated' => false];
        }

        $source = trim($_POST['source'] ?? '', '/');

        if ($source === '' || !defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültiger Quell-Pfad.']);
            header('HX-Redirect: /system/redirects');
            exit;
        }

        $current  = $this->getRedirects();
        $filtered = array_filter($current, fn($r) => $r['source'] !== $source);

        $this->saveRedirectsFile(array_values($filtered));
        Session::setValue('admin_flash', ['type' => 'success', 'message' => "Redirect '/{$source}' gelöscht."]);

        header('HX-Redirect: /system/redirects');
        exit;
    }
}
