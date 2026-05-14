<?php
namespace Vinou\SiteBuilder\Tools;

use \Vinou\SiteBuilder\Processors\ProcessorInterface;

/**
 * Contract for the SiteBuilder rendering engine.
 *
 * Defines the public method surface that DynamicRoutes, Site, and other
 * consumers depend on. Render implements this interface; a test double or
 * alternative renderer must satisfy these signatures.
 *
 * Note: public properties (api, renderArr, processors) are not part of this
 * interface. Full encapsulation of those is deferred to the Priority 4
 * accessor refactoring that introduces getter/setter pairs.
 */
interface RendererInterface {

    /**
     * Initialises the API connection and loads localization data.
     *
     * @return true
     */
    public function connect(): true;

    /**
     * Registers a processor under a named key.
     *
     * @param string             $processor  Processor key (e.g. 'shop', 'mailer').
     * @param ProcessorInterface $object     Processor instance.
     * @return void
     */
    public function loadProcessor(string $processor, ProcessorInterface $object): void;

    /**
     * Executes the dataProcessing pipeline from route YAML.
     *
     * @param array<string, mixed>|null $options  Keyed processing steps.
     * @param array<int, mixed>         $data     URL wildcard segments.
     * @return void
     */
    public function dataProcessing(?array $options = null, array $data = []): void;

    /**
     * Maps URL wildcard arguments to named keys in renderArr['urlParams'].
     *
     * @param list<mixed>               $arguments  URL wildcard values.
     * @param array<string, mixed>|null $options    Route options.
     * @return void
     */
    public function loadUrlParams(array $arguments, ?array $options = null): void;

    /**
     * Renders a Twig template and sends the output to the client.
     *
     * @param string                    $template  Twig template filename.
     * @param array<string, mixed>|null $options   Route options.
     * @return never
     */
    public function renderPage(string $template = 'Default.twig', ?array $options = null): never;

    /**
     * Merges route option values into the template context.
     *
     * @param array<string, mixed>|string $options
     * @return void
     */
    public function renderOptions(array|string $options): void;

    /**
     * Redirects to $target (external or internal).
     *
     * @param string $target
     * @return void
     */
    public function redirect(string $target): void;

    /**
     * Redirects to the configured permission redirect URL.
     *
     * @return void
     */
    public function forbidden(): void;

    /**
     * Sets the webroot-relative base path for default template directories.
     *
     * @param string $path
     * @return void
     */
    public function setTemplateRootPath(string $path): void;

    /**
     * Replaces the list of template sub-directory names.
     *
     * @param list<string> $directories
     * @return void
     */
    public function setTemplateDirectories(array $directories): void;

    /**
     * Appends the default webroot template directories to the storage list.
     *
     * @return void
     */
    public function loadDefaultStorages(): void;

    /**
     * Appends template directories from a root directory to the storage list.
     *
     * @param string       $rootDir
     * @param list<string> $folders
     * @return void
     */
    public function addTemplateStorages(string $rootDir, array $folders = []): void;

    /**
     * Sets the supported language codes for URL-based language detection.
     *
     * @param list<string> $keys
     * @return void
     */
    public function setLanguages(array $keys): void;

    /**
     * Sets the default language code.
     *
     * @param string $key
     * @return void
     */
    public function setDefaultLanguage(string $key): void;

    /**
     * Stores the shop settings config block.
     *
     * @param array<string, mixed>|null $config
     * @return void
     */
    public function setConfig(?array $config = null): void;

    /**
     * Stores a settings snapshot.
     *
     * @param array<string, mixed>|null $settings
     * @return void
     */
    public function setSettings(?array $settings = null): void;
}
