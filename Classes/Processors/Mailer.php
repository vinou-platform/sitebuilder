<?php
namespace Vinou\SiteBuilder\Processors;

use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\Exception;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Session\Session;
use \Twig\Loader\FilesystemLoader;
use \Twig\Environment;
use \Twig\TwigFilter;
use \Twig\Extension\DebugExtension;
use \SimpleCaptcha\Builder;
use \Symfony\Component\Yaml\Yaml;

/**
 * Processor for sending emails and rendering mail templates in dataProcessing steps.
 *
 * Handles SMTP configuration, Twig-based mail rendering, form submission
 * validation, captcha generation and verification, and shop order notifications.
 * Registered under the key 'mailer' by default in Site::loadDefaultProcessors().
 *
 * Configuration is read from config/mail.yml (or the path set via setConfigFile()).
 */
class Mailer implements ProcessorInterface {

    /** @var Api|null Vinou API instance, optional for processors not requiring API access. */
    protected ?Api $api = null;

    /** @var string Root path for mail template resolution, relative to this class file. */
    protected string $templateRootPath = '../../Resources/';

    /** @var list<string> Sub-directories within templateRootPath to search for templates. */
    protected array $templateDirectories = ['Mail/'];

    /** @var string Default template filename used when no explicit template is set. */
    protected string $template = 'Default.twig';

    /** @var list<string> Absolute paths to template directories, in search order. */
    public array $storage = [];

    /** @var list<string> Built-in fallback directories appended after $storage in search order. */
    private array $defaultStorage = [];

    /** @var bool Whether built-in and theme fallback directories are included in template search. */
    protected bool $includeDefaults = true;

    /** @var Environment|null Twig environment instance, initialised lazily before sending. */
    protected ?Environment $renderer = null;

    /** @var string Path to the mail configuration YAML file. */
    protected string $configFile = 'mail.yml';

    /** @var PHPMailer|null PHPMailer instance for SMTP dispatch. */
    protected ?PHPMailer $mailer = null;

    /** @var array<string, mixed> Parsed mail.yml configuration. */
    protected array $config = [];

    /** @var array<string, mixed> Captcha rendering configuration with all supported options. */
    protected array $captchaConfig = [];

    /** @var bool Whether per-captcha config overrides from route params are accepted. */
    protected bool $allowIndividualCaptcha = true;

    /** @var array<string, mixed> Active form configuration loaded from mail.yml. */
    protected array $formconfig = [];

    /** @var bool Whether captcha validation is required for form submission. */
    protected bool $useCaptcha = true;

    /** @var bool Whether to use a randomly named POST field for the captcha phrase. */
    protected bool $dynamicCaptchaInput = false;

    /** @var bool Whether a copy of outgoing mails is sent to the sender. */
    protected bool $sendCopyToSender = false;

    /** @var list<array<string, mixed>> Queue of mail definitions prepared for dispatch. */
    protected array $mails = [];

    /** @var string|null Sender email address. */
    public ?string $fromMail = null;

    /** @var string|null Sender display name. */
    public ?string $fromName = null;

    /** @var string|null Recipient email address. */
    public ?string $receiver = null;

    /** @var string|null Email subject line. */
    public ?string $subject = null;

    /** @var array<string, mixed> Template data passed to the Twig render context. */
    public array $data = [];

    /**
     * @param Api|null $api  Optional Vinou API instance; required for form processing.
     */
    public function __construct(?Api $api = null) {
        if (!is_null($api))
            $this->api = $api;

        $this->initMailer();
        $this->loadDefaultTemplateStorage();

        $this->captchaConfig = [
            'distort'             => false,
            'interpolate'         => false,
            'maxLinesBehind'      => mt_rand(1, 4),
            'maxLinesFront'       => mt_rand(1, 4),
            'bgColor'             => [100, 0, 0],
            'lineColor'           => [99, 99, 99],
            'textColor'           => [255, 255, 255],
            'maxAngle'            => mt_rand(0, 5),
            'maxOffset'           => mt_rand(0, 5),
            'applyEffects'        => false,
            'applyNoise'          => false,
            'noiseFactor'         => mt_rand(1, 10),
            'applyPostEffects'    => true,
            'applyScatterEffect'  => false,
            'randomizeFonts'      => true,
            'width'               => 120,
            'height'              => 50
        ];

        $this->loadConfig();
    }

    /**
     * Initialises a fresh PHPMailer instance with UTF-8 / base64 encoding.
     */
    public function initMailer(): void {
        $this->mailer          = new PHPMailer();
        $this->mailer->CharSet  = 'UTF-8';
        $this->mailer->Encoding = 'base64';
    }

    /**
     * Renders the current template and dispatches a single email.
     *
     * @return bool|string  true on success, PHPMailer error string on failure.
     */
    public function send(): bool|string {
        $this->initTwig();
        $mailcontent = $this->render();

        $this->mailer->setFrom($this->fromMail, $this->fromName, 0);
        $this->mailer->addReplyTo($this->fromMail);
        $this->mailer->addAddress($this->receiver);
        $this->mailer->Subject = $this->subject;
        $this->mailer->msgHTML($mailcontent);

        if (!$this->mailer->send()) {
            return $this->mailer->ErrorInfo;
        }

        Session::deleteValue('captcha');
        return true;
    }

    /**
     * Dispatches all mails queued in $this->mails.
     *
     * Each entry in the queue may override template, subject, and receiver.
     * The captcha session value is cleared after successful dispatch of all mails.
     *
     * @return bool|string  true on success, PHPMailer error string on first failure.
     */
    public function sendMails(): bool|string {
        $this->initTwig();

        foreach ($this->mails as $mail) {
            $mailcontent = $this->render($mail['template'], $mail['data']);

            $this->mailer->setFrom($this->fromMail, $this->fromName, 0);
            $this->mailer->addReplyTo($this->fromMail);

            if (isset($mail['subject']))
                $this->setSubject($mail['subject']);

            if (isset($mail['receiver']))
                $this->setReceiver($mail['receiver']);

            $this->mailer->ClearAllRecipients();
            $this->mailer->addAddress($this->receiver);
            $this->mailer->Subject = $this->subject;
            $this->mailer->msgHTML($mailcontent);

            if (!$this->mailer->send())
                return $this->mailer->ErrorInfo;
        }

        Session::deleteValue('captcha');
        return true;
    }

    /**
     * Sets the template filename used for rendering.
     *
     * @param string $template  Filename relative to the configured template directories.
     */
    public function setTemplate(string $template): void {
        $this->template = $template;
    }

    /**
     * Sets the Twig template context data.
     *
     * @param array<string, mixed> $data  Variables available in the mail template.
     */
    public function setData(array $data): void {
        $this->data = $data;
    }

    /**
     * Sets the sender address and display name.
     *
     * @param string $email  Sender email address.
     * @param string $name   Sender display name.
     */
    public function setFrom(string $email, string $name): void {
        $this->fromMail = $email;
        $this->fromName = $name;
    }

    /**
     * Sets the recipient email address.
     *
     * @param string $receiver  Email address of the intended recipient.
     */
    public function setReceiver(string $receiver): void {
        $this->receiver = $receiver;
    }

    /**
     * Sets the email subject line.
     *
     * @param string $subject  Subject string for the outgoing mail.
     */
    public function setSubject(string $subject): void {
        $this->subject = $subject;
    }

    /**
     * Overrides the default mail.yml configuration file path.
     *
     * @param string $file  Path relative to VINOU_CONFIG_DIR or absolute path.
     */
    public function setConfigFile(string $file): void {
        $this->configFile = $file;
    }

    /**
     * Attaches shop legal documents (AGB, Widerruf, etc.) defined in mail.yml.
     *
     * Reads the shop.attachments map from the loaded config and attaches each
     * existing file to the current PHPMailer instance.
     */
    public function loadShopAttachments(): void {
        if (!isset($this->config['shop']['attachments']))
            return;

        foreach ($this->config['shop']['attachments'] as $file) {
            $absFile = Helper::getNormDocRoot() . $file;
            if (is_file($absFile))
                $this->mailer->addAttachment($absFile);
        }
    }

    /**
     * Generates a captcha image and stores the phrase in the session.
     *
     * If a captcha phrase already exists in the session it is reused,
     * so the same challenge is shown on form re-renders after validation errors.
     * Route params can override individual captcha config values unless
     * $allowIndividualCaptcha was disabled via loadCaptchaConfig().
     *
     * @param array<string, mixed>|null $params  Optional captcha overrides from the route.
     * @return array{phrase: string, image: string, field: string}  Captcha data for the template.
     */
    public function loadCaptcha(?array $params = null): array {
        $phrase = Session::getValue('captcha');
        if (!$phrase) {
            $phrase = Builder::buildPhrase(5, 'abcdefghijklmnopqrstuvwxyz@0123456789');
            Session::setValue('captcha', $phrase);
        }

        $captcha = new Builder($phrase);

        foreach ($this->captchaConfig as $property => $value) {
            if (isset($params[$property]) && $this->allowIndividualCaptcha) {
                $value = $params[$property];
                if (in_array($property, ['bgColor', 'lineColor', 'textColor'])) {
                    [$r, $g, $b] = explode(',', $params[$property]);
                    $value = [(int)$r, (int)$g, (int)$b];
                }
            }
            $captcha->$property = $value;
        }

        if ($this->captchaConfig['width'] % 2 === 1)  $this->captchaConfig['width']++;
        if ($this->captchaConfig['height'] % 2 === 1) $this->captchaConfig['height']++;

        $captcha->build($this->captchaConfig['width'], $this->captchaConfig['height']);

        if (isset($params['dynamicCaptchaInput']))
            $this->dynamicCaptchaInput = (bool)$params['dynamicCaptchaInput'];

        return [
            'phrase' => $captcha->phrase,
            'image'  => $captcha->inline(),
            'field'  => $this->dynamicCaptchaInput ? bin2hex(random_bytes(20)) : ''
        ];
    }

    /**
     * Validates the captcha phrase submitted with the current POST request.
     *
     * @return bool  true if the submitted phrase matches the session value.
     */
    public function validateCaptcha(): bool {
        return Helper::validateCaptcha($this->dynamicCaptchaInput);
    }

    /**
     * Processes a standard HTML form POST and dispatches the configured mails.
     *
     * Does nothing if the request is not a POST with submitted=1.
     * Returns a captcha error array if captcha validation fails.
     *
     * @param array<string, mixed> $params  Route parameters; must contain key 'form'
     *                                      matching a form key in mail.yml.
     * @return bool|array<string, string>|string  true on success, captcha error array,
     *                                             PHPMailer error string, or false if not submitted.
     */
    public function sendPostForm(array $params): bool|array|string {
        if (empty($_POST) || !isset($_POST['submitted']) || $_POST['submitted'] == 0)
            return false;

        $this->loadFormConfig($params, $_POST);

        if ($this->useCaptcha && !$this->validateCaptcha())
            return ['captchaerror' => 'captcha could not be detected or is invalid'];

        return $this->sendMails();
    }

    /**
     * Processes a JSON-encoded form submission from the request body.
     *
     * Reads php://input, decodes the JSON payload, and dispatches configured mails.
     *
     * @return bool|array<string, mixed>|string  true on success, false if payload is missing,
     *                                            or PHPMailer error string on failure.
     */
    public function sendJSONForm(): bool|array|string {
        $inputJSON = file_get_contents('php://input');
        $data      = json_decode($inputJSON, true);

        if (!isset($data['data']))
            return false;

        $this->loadFormConfig($data, $data['data']);
        return $this->sendMails();
    }

    /**
     * Builds the mail queue from a form configuration and submitted data.
     *
     * Resolves the form key against mail.yml, filters submitted fields to the
     * declared whitelist, and pushes the main mail and optional confirmation
     * mail onto the $mails queue.
     *
     * @param array<string, mixed> $config  Must contain key 'form' matching a mail.yml form key.
     * @param array<string, mixed> $data    Raw submitted form data to filter and embed.
     * @throws \Exception If the API is not initialised, no forms are configured,
     *                    or the form key is invalid.
     */
    public function loadFormConfig(array $config, array $data): void {
        $formconfig = $this->validateFormConfig($config);

        $mail     = [];
        $maildata = [];

        if (isset($formconfig['subject'])) {
            $mail['subject']    = $formconfig['subject'];
            $maildata['title']  = $formconfig['subject'];
        }

        if (isset($formconfig['receiver']))
            $mail['receiver'] = $formconfig['receiver'];

        if (isset($formconfig['template']))
            $mail['template'] = $formconfig['template'];

        if (isset($formconfig['disableCaptcha']))
            $this->useCaptcha = !(bool)$formconfig['disableCaptcha'];

        $maildata['formdata'] = [];
        foreach ($data as $field => $value) {
            if (in_array($field, $formconfig['fields']))
                $maildata['formdata'][$field] = $value;
        }

        $maildata['customer'] = $this->api->getCustomer();
        $mail['data']         = $maildata;
        $this->mails[]        = $mail;

        if (!isset($formconfig['confirmation']))
            return;

        $mailconfig             = $this->validateMailConfig($formconfig['confirmation']);
        $maildata['title']      = $mailconfig['subject'];
        $this->mails[]          = [
            'subject'  => $mailconfig['subject'],
            'receiver' => $data[$mailconfig['receiver']],
            'template' => $mailconfig['template'],
            'data'     => $maildata
        ];
    }

    /**
     * Validates a form submission config array against the loaded mail.yml forms.
     *
     * @param array<string, mixed> $config  Must contain key 'form'.
     * @return array<string, mixed>  The resolved form configuration from mail.yml.
     * @throws \Exception On missing API, missing forms config, missing form key, or unknown key.
     */
    public function validateFormConfig(array $config): array {
        if (is_null($this->api))
            throw new \Exception('no api initialized');

        if (!isset($this->config['forms']))
            throw new \Exception('no forms are defined');

        if (!isset($config['form']))
            throw new \Exception('no form selected');

        $formKey = $config['form'];

        if (!isset($this->config['forms'][$formKey]))
            throw new \Exception('a form with this key doesnt exists');

        $formconfig = $this->config['forms'][$formKey];

        if (!isset($formconfig['fields']))
            throw new \Exception('no param fields defined in form config');

        return $formconfig;
    }

    /**
     * Validates that a mail configuration contains all required keys.
     *
     * @param array<string, mixed> $config  Mail configuration to validate.
     * @return array<string, mixed>  The validated config, unchanged.
     * @throws \Exception If any of 'subject', 'receiver', or 'template' are missing.
     */
    public function validateMailConfig(array $config): array {
        foreach (['subject', 'receiver', 'template'] as $field) {
            if (!isset($config[$field]))
                throw new \Exception('no ' . $field . ' defined');
        }
        return $config;
    }

    /**
     * Parses mail.yml and applies SMTP, sender defaults, template paths, and captcha config.
     *
     * @throws \Exception If the config file cannot be resolved.
     */
    public function loadConfig(): void {
        if (!is_file($this->configFile) && !is_file(Helper::getNormDocRoot() . VINOU_CONFIG_DIR . $this->configFile))
            throw new \Exception('Configuration file ' . $this->configFile . ' could not be solved');

        $absFile     = str_starts_with($this->configFile, '/')
            ? $this->configFile
            : Helper::getNormDocRoot() . VINOU_CONFIG_DIR . $this->configFile;
        $this->config = Yaml::parseFile($absFile);

        if (isset($this->config['smtp']))
            $this->loadSMTPConfig();

        if (isset($this->config['defaults'])) {
            $defaults = $this->config['defaults'];
            if (isset($defaults['fromName'], $defaults['fromMail']))
                $this->setFrom($defaults['fromMail'], $defaults['fromName']);
            if (isset($defaults['receiver']))
                $this->setReceiver($defaults['receiver']);
            if (isset($defaults['subject']))
                $this->setSubject($defaults['subject']);
        }

        if (isset($this->config['template']['rootDir'], $this->config['template']['directories']))
            $this->loadTemplateDirectories(
                $this->config['template']['rootDir'],
                $this->config['template']['directories']
            );

        if (isset($this->config['template']['includeDefaults']) && $this->config['template']['includeDefaults'] === false) {
            $this->includeDefaults = false;
            $this->defaultStorage  = [];
        }

        if (isset($this->config['captcha']))
            $this->loadCaptchaConfig($this->config['captcha']);
    }

    /**
     * Merges a captcha override configuration into the default captcha config.
     *
     * Disables per-request captcha overrides after a static config is applied,
     * so that route-level params can no longer change captcha appearance.
     *
     * @param array<string, mixed> $overrideConfig  Partial captcha config to merge.
     */
    public function loadCaptchaConfig(array $overrideConfig = []): void {
        foreach ($this->captchaConfig as $key => $value) {
            if (isset($overrideConfig[$key]))
                $this->captchaConfig[$key] = $overrideConfig[$key];
        }
        $this->allowIndividualCaptcha = false;
    }

    /**
     * Applies the SMTP settings from mail.yml to the PHPMailer instance.
     */
    public function loadSMTPConfig(): void {
        $config = $this->config['smtp'];

        $this->mailer->isSMTP();
        $this->mailer->SMTPDebug = 0;
        $this->mailer->Host      = $config['host'];
        $this->mailer->Port      = $config['port'];

        if (isset($config['encrypt']))
            $this->mailer->SMTPSecure = $config['encrypt'];

        $this->mailer->SMTPAuth = $config['auth'] ?? true;

        if (isset($config['authType']))
            $this->mailer->AuthType = $config['authType'];

        if (!empty($config['disableSSLCheck'])) {
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];
        }

        $this->mailer->Username = $config['username'];
        $this->mailer->Password = $config['password'];
    }

    /**
     * Tests the SMTP connection without sending a mail.
     *
     * @return string  'Connected' on success, or the PHPMailer error info string.
     */
    public function testSMTPConfig(): string {
        $this->mailer->setFrom($this->fromMail, $this->fromName, 0);

        if ($this->mailer->smtpConnect()) {
            $this->mailer->smtpClose();
            return 'Connected';
        }

        return $this->mailer->ErrorInfo;
    }

    /**
     * Appends the theme's Resources/Mail/ directory to the template storage as a fallback.
     *
     * Must be called after construction, e.g. from Site::loadDefaultProcessors().
     * Skipped when includeDefaults is false or the directory does not exist.
     *
     * @param string       $rootDir    Theme root directory (absolute or webroot-relative).
     * @param list<string> $subfolders Sub-directories to append (e.g. ['Mail/']).
     */
    public function addThemeMailStorage(string $rootDir, array $subfolders): void {
        if (!$this->includeDefaults)
            return;

        if (!str_starts_with($rootDir, '/'))
            $rootDir = Helper::getNormDocRoot() . $rootDir;

        foreach ($subfolders as $directory) {
            $path = $rootDir . $directory;
            if (is_dir($path) && !empty(glob($path . '*.twig')))
                $this->storage[] = $path;
        }
    }

    /**
     * Registers the built-in Resources/Mail/ directory as the lowest-priority fallback.
     */
    private function loadDefaultTemplateStorage(): void {
        $rootDir = __DIR__ . '/' . $this->templateRootPath;
        foreach ($this->templateDirectories as $directory) {
            $this->defaultStorage[] = $rootDir . $directory;
        }
    }

    /**
     * Resolves template directories and prepends them to the storage list.
     *
     * Internal paths (relative to this class file) are resolved using __DIR__.
     * Absolute paths are used as-is. All other paths are resolved against the webroot.
     *
     * @param string       $rootDir    Root directory for the template paths.
     * @param list<string> $subfolders Sub-directory names to append to $rootDir.
     * @param bool         $internal   True if $rootDir is relative to this class file.
     */
    private function loadTemplateDirectories(string $rootDir, array $subfolders, bool $internal = false): void {
        if (str_starts_with($rootDir, '..') && $internal)
            $rootDir = __DIR__ . '/' . $rootDir;
        elseif (!str_starts_with($rootDir, '/'))
            $rootDir = Helper::getNormDocRoot() . $rootDir;

        foreach ($subfolders as $directory) {
            array_unshift($this->storage, $rootDir . $directory);
        }
    }

    /**
     * Renders a Twig mail template and returns the HTML string.
     *
     * @param string|null               $template  Template filename; falls back to $this->template.
     * @param array<string, mixed>|null $data      Template context; falls back to $this->data.
     * @return string  Rendered HTML content of the mail.
     */
    private function render(?string $template = null, ?array $data = null): string {
        $template ??= $this->template;
        $data     ??= $this->data;

        $data['domain']   = $_SERVER['SERVER_NAME'];
        $data['protocol'] = Helper::fetchProtocol();

        return $this->renderer->load($template)->render($data);
    }

    /**
     * Initialises the Twig environment for mail rendering.
     *
     * Uses the storage paths built up by loadTemplateDirectories(). Cache and
     * debug mode follow the global VINOU_CACHE and VINOU_DEBUG constants.
     *
     * @return Environment  The configured Twig environment instance.
     */
    private function initTwig(): Environment {
        $loader = new FilesystemLoader(array_merge($this->storage, $this->defaultStorage));

        $this->renderer = new Environment($loader, [
            'cache' => defined('VINOU_CACHE') ? VINOU_CACHE : Helper::getNormDocRoot() . 'Cache/Twig',
            'debug' => defined('VINOU_DEBUG') ? VINOU_DEBUG : false
        ]);

        $this->renderer->addExtension(new DebugExtension());
        $this->renderer->addExtension(new \Vinou\Translations\TwigExtensionV3(
            $this->config['language'] ?? 'de'
        ));

        $this->renderer->addFilter(new TwigFilter('base64image', function (string $url): string {
            return Helper::imageToBase64($url);
        }));

        $this->renderer->addFilter(new TwigFilter('withAttribute', function (array $arr, string $attr, mixed $value): array {
            return array_filter($arr, function (array $item) use ($attr, $value): bool {
                if (is_array($item[$attr]))
                    return isset($item[$attr][$value]);
                return $item[$attr] == $value;
            });
        }));

        $this->renderer->addFilter(new TwigFilter('getWinery', function (int $id): mixed {
            return $this->api->getWinery($id);
        }));

        return $this->renderer;
    }
}
