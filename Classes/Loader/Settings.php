<?php
namespace Vinou\SiteBuilder\Loader;

use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Services\ServiceLocator;
use \Symfony\Component\Yaml\Yaml;

/**
 * Collects and merges YAML settings files into the global settings service.
 *
 * Loading order determines priority: files added later override earlier ones
 * via array_replace_recursive. The SiteBuilder's own Configuration/settings.yml
 * acts as the lowest-priority fallback and is only loaded when explicitly enabled
 * via system.load.defaultSettings: true in the merged result.
 *
 * Typical load order (lowest to highest priority):
 *   1. Theme:   YourTheme/Configuration/settings.yml  (added via addByDirectory)
 *   2. Project: config/settings.yml                   (added in load() via VINOU_CONFIG_DIR)
 *   3. Default: vendor/.../Configuration/settings.yml (only if defaultSettings: true)
 */
class Settings {

    /** @var list<string> Absolute paths to settings YAML files, in load order. */
    protected array $files = [];

    /** @var string|false Absolute path to the SiteBuilder built-in settings.yml fallback. */
    protected string|false $defaultFile = false;

    /** @var object Settings service instance from the service locator. */
    protected object $settingsService;

    public function __construct() {
        $this->settingsService = ServiceLocator::get('Settings');
        $classPaths            = explode('/Classes', Helper::getClassPath(get_class($this)));
        $dir                   = array_shift($classPaths);
        $this->defaultFile     = $dir . '/Configuration/settings.yml';
    }

    /**
     * Registers a settings.yml file found inside a directory.
     *
     * Resolves the file at $dir . $subdir . 'settings.yml'. If $subdir is
     * false the file is expected directly inside $dir.
     *
     * @param string      $dir     Directory to search in; relative paths are resolved
     *                             against the webroot.
     * @param string|false $subdir Sub-directory appended to $dir, default '/Configuration/'.
     * @throws \Exception If $dir does not exist or the settings.yml is missing.
     */
    public function addByDirectory(string $dir, string|false $subdir = '/Configuration/'): void {
        if (!self::isAbsolute($dir))
            $dir = Helper::getNormDocRoot() . $dir;

        if (!is_dir($dir))
            throw new \Exception("directory $dir doesn't exists", 1);

        $file = $subdir !== false ? $dir . $subdir . 'settings.yml' : $dir . '/settings.yml';
        $this->addFile($file);
    }

    /**
     * Registers an absolute path to a settings YAML file.
     *
     * Relative paths are resolved via realpath(). The file must exist at
     * registration time; missing files throw immediately rather than failing
     * silently at load time.
     *
     * @param string $file  Absolute or resolvable path to a settings YAML file.
     * @throws \Exception If $file is empty or cannot be resolved to an existing file.
     */
    public function addFile(string $file): void {
        if (!self::isAbsolute($file))
            $file = realpath($file);

        if (!is_file($file))
            throw new \Exception("file $file doesn't exists", 1);

        $this->files[] = $file;
    }

    /**
     * Merges all registered settings files and writes the result to the settings service.
     *
     * Execution order:
     *   1. Appends the project config from VINOU_CONFIG_DIR (if defined).
     *   2. Iterates all registered files and merges them via array_replace_recursive;
     *      later files override earlier ones.
     *   3. If the merged result contains system.load.defaultSettings: true (or no files
     *      were loaded at all), the SiteBuilder built-in defaults are merged underneath
     *      as a base layer via array_replace_recursive($defaults, $merged).
     *   4. Each top-level key in the final result is pushed into the settings service.
     *
     * @return array<string, mixed>  The fully merged settings array.
     */
    public function load(): array {
        if (defined('VINOU_CONFIG_DIR'))
            $this->addByDirectory(VINOU_CONFIG_DIR, false);

        $settings = [];
        foreach ($this->files as $file) {
            $fileSettings = Yaml::parseFile($file);
            $settings     = array_replace_recursive($settings, $fileSettings);
        }

        $loadDefaults = isset($settings['system']['load']['defaultSettings'])
            && $settings['system']['load']['defaultSettings'];

        if ($loadDefaults || empty($settings)) {
            $defaultSettings = Yaml::parseFile($this->defaultFile);
            $settings        = array_replace_recursive($defaultSettings, $settings);
        }

        foreach ($settings as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        return $settings;
    }

    /**
     * Returns true if the given path starts with a directory separator.
     *
     * @param string $path  File system path to check.
     * @return bool
     */
    public static function isAbsolute(string $path): bool {
        return str_starts_with($path, '/');
    }
}
