<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\FileHandler\Images;
use \Vinou\ApiConnector\FileHandler\Pdf;
use \Vinou\ApiConnector\Services\ServiceLocator;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Vinou\SiteBuilder\Router\DynamicRoutes;
use \Vinou\SiteBuilder\Tools\ImageService;
use \Symfony\Component\Yaml\Yaml;

/**
 * Processor for the SiteBuilder admin panel.
 *
 * Security: bcrypt password hashing (auto-migrates plaintext), brute-force
 * protection (5 attempts → 15 min lockout), per-session CSRF tokens, and
 * optional TOTP two-factor authentication (RFC 6238, no external library).
 *
 * Registered under the key 'admin' by Site::loadAdminPanel() when
 * system.password is set in the project's settings.yml.
 */
class Admin implements ProcessorInterface {

    private const SESSION_KEY      = 'vinou_admin_auth';
    private const SESSION_KEY_PW   = 'vinou_admin_pw_ok';
    private const SESSION_KEY_USER = 'vinou_admin_user_email';
    private const SESSION_KEY_CSRF = 'admin_csrf_token';
    private const SESSION_KEY_BF   = 'admin_login_attempts';
    private const BF_MAX_ATTEMPTS  = 5;
    private const BF_LOCKOUT_SECS  = 900; // 15 minutes

    /** Top-level settings keys editable via the Settings panel. Everything else (system, shop, …) is read-only there. */
    private const SETTINGS_WHITELIST = ['additionalContent', 'settings', 'vinou'];

    /** @var array<string, mixed> */
    public array $data = [];

    private object $settingsService;
    private DynamicRoutes $routeConfig;
    private ?string $themeDir;

    public function __construct(DynamicRoutes &$routeConfig, ?string $themeDir = null) {
        $this->settingsService = ServiceLocator::get('Settings');
        $this->routeConfig     = $routeConfig;
        $this->themeDir        = $themeDir;
    }

    // ─────────────────────── Login flow ───────────────────────

    /**
     * Step 1: password login. Redirects to /system/totp if TOTP is configured.
     *
     * @return array<string, mixed>
     */
    public function init(): array {
        $system    = $this->settingsService->get('system') ?? [];
        $csrfToken = $this->getCsrfToken();

        $multiUser = !empty($system['users']) && is_array($system['users']);

        if (!isset($system['password']) && !$multiUser)
            return ['authenticated' => false, 'step' => 'password', 'version' => $this->getVersion(),
                    'error' => 'Kein Admin-Passwort in system.password gesetzt.', 'csrfToken' => $csrfToken];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            if (!$this->verifyCsrfToken())
                return ['authenticated' => false, 'step' => 'password', 'multiUser' => $multiUser,
                        'version' => $this->getVersion(), 'error' => 'Ungültige Anfrage.', 'csrfToken' => $csrfToken];

            $lockError = $this->checkBruteForce();
            if ($lockError !== null)
                return ['authenticated' => false, 'step' => 'password', 'multiUser' => $multiUser,
                        'version' => $this->getVersion(), 'error' => $lockError, 'csrfToken' => $csrfToken];

            $authenticated = false;

            if ($multiUser) {
                // New multi-user format: match by email, then verify password
                $email = strtolower(trim($_POST['email'] ?? ''));
                foreach ($system['users'] as $idx => $user) {
                    if (!is_array($user) || strtolower(trim($user['email'] ?? '')) !== $email) continue;
                    $stored = $user['password'] ?? '';
                    if ($this->verifyPassword($_POST['password'], $stored)) {
                        if ($this->isPlaintext($stored))
                            $this->upgradePasswordHash($_POST['password'], "system.users.{$idx}.password");
                        $authenticated = true;
                    }
                    break; // email found — no need to check further, regardless of password
                }
            } else {
                // Legacy single-password format
                $stored = $system['password'] ?? '';
                if ($stored !== '' && $this->verifyPassword($_POST['password'], $stored)) {
                    if ($this->isPlaintext($stored))
                        $this->upgradePasswordHash($_POST['password'], 'system.password');
                    $authenticated = true;
                }
            }

            if ($authenticated) {
                $this->resetBruteForce();
                if ($multiUser) {
                    Session::setValue(self::SESSION_KEY_USER, $email);
                    Session::setValue(self::SESSION_KEY_PW, true);
                    $userSecrets = $this->getUserTotpSecrets($system, $email);
                    Redirect::internal(empty($userSecrets) ? '/system/totp/first-setup' : '/system/totp');
                }
                // Legacy single-password mode
                if (!empty($this->getTotpSecrets($system))) {
                    Session::setValue(self::SESSION_KEY_PW, true);
                    Redirect::internal('/system/totp');
                }
                Session::setValue(self::SESSION_KEY, true);
                Redirect::internal('/system/settings');
            }

            $this->recordFailedAttempt();
            $errorMsg = $multiUser ? 'E-Mail oder Passwort falsch.' : 'Falsches Passwort.';
            return ['authenticated' => false, 'step' => 'password', 'multiUser' => $multiUser,
                    'version' => $this->getVersion(), 'error' => $errorMsg, 'csrfToken' => $csrfToken];
        }

        if (!$this->isAuthenticated())
            return ['authenticated' => false, 'step' => 'password', 'multiUser' => $multiUser,
                    'version' => $this->getVersion(), 'csrfToken' => $csrfToken];

        if (($_GET['action'] ?? null) === 'logout') {
            Session::deleteValue(self::SESSION_KEY);
            Session::deleteValue(self::SESSION_KEY_PW);
            Session::deleteValue(self::SESSION_KEY_USER);
            Redirect::internal('/system');
        }

        if (($_GET['action'] ?? null) === 'instagram_connect') {
            $ig    = (array)($this->settingsService->get('instagram') ?? []);
            $appId = $ig['app_id'] ?? '';
            if (!$appId) {
                Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Instagram App ID nicht konfiguriert.']);
                Redirect::internal('/system/system');
            }
            $processor = new Instagram();
            Redirect::external($processor->getAuthUrl($appId, $this->buildInstagramRedirectUri()));
        }

        if (($_GET['action'] ?? null) === 'instagram_callback') {
            $code = $_GET['code'] ?? null;
            if (!$code) {
                Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Instagram-Verbindung abgebrochen.']);
                Redirect::internal('/system/system');
            }
            $ig        = (array)($this->settingsService->get('instagram') ?? []);
            $appId     = $ig['app_id']     ?? '';
            $appSecret = $ig['app_secret'] ?? '';
            if (!$appId || !$appSecret) {
                Session::setValue('admin_flash', ['type' => 'error', 'message' => 'App ID oder App Secret fehlen.']);
                Redirect::internal('/system/system');
            }
            $processor   = new Instagram();
            $redirectUri = $this->buildInstagramRedirectUri();
            $shortToken  = $processor->exchangeCode($code, $appId, $appSecret, $redirectUri);
            if (empty($shortToken['access_token'])) {
                Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Token-Austausch fehlgeschlagen. Bitte erneut versuchen.']);
                Redirect::internal('/system/system');
            }
            $longToken = $processor->getLongLivedToken($shortToken['access_token'], $appSecret);
            if (empty($longToken['access_token'])) {
                Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Long-lived Token konnte nicht ausgestellt werden.']);
                Redirect::internal('/system/system');
            }
            $me = $processor->getMe($longToken['access_token']);
            $this->writeInstagramToken([
                'access_token'  => $longToken['access_token'],
                'token_expires' => time() + (int)($longToken['expires_in'] ?? 5183944),
                'user_id'       => $me['id']       ?? ($shortToken['user_id'] ?? ''),
                'username'      => $me['username']  ?? '',
            ]);
            Session::setValue('admin_flash', ['type' => 'success', 'message' => 'Instagram erfolgreich verbunden.']);
            Redirect::internal('/system/system');
        }

        Redirect::internal('/system/settings');
    }

    /**
     * Step 2: TOTP verification. Only accessible after password step sets SESSION_KEY_PW.
     *
     * @return array<string, mixed>
     */
    public function verifyTotpStep(): array {
        if ($this->isAuthenticated())
            Redirect::internal('/system/settings');

        $csrfToken = $this->getCsrfToken();

        if (Session::getValue(self::SESSION_KEY_PW) !== true)
            Redirect::internal('/system');

        $system    = $this->settingsService->get('system') ?? [];
        $multiUser = !empty($system['users']) && is_array($system['users']);

        if ($multiUser) {
            $email = Session::getValue(self::SESSION_KEY_USER) ?? '';
            if (empty($email)) Redirect::internal('/system');
            $secrets = $this->getUserTotpSecrets($system, $email);
            // No secrets means user hasn't set up 2FA yet — send to forced setup
            if (empty($secrets)) Redirect::internal('/system/totp/first-setup');
        } else {
            $secrets = $this->getTotpSecrets($system);
            // If all secrets were removed between steps, grant access directly
            if (empty($secrets)) {
                Session::deleteValue(self::SESSION_KEY_PW);
                Session::setValue(self::SESSION_KEY, true);
                Redirect::internal('/system/settings');
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
            if (!$this->verifyCsrfToken())
                return ['authenticated' => false, 'step' => 'totp', 'version' => $this->getVersion(),
                        'error' => 'Ungültige Anfrage.', 'csrfToken' => $csrfToken];

            $lockError = $this->checkBruteForce();
            if ($lockError !== null)
                return ['authenticated' => false, 'step' => 'totp', 'version' => $this->getVersion(),
                        'error' => $lockError, 'csrfToken' => $csrfToken];

            $code = trim($_POST['otp_code']);
            foreach ($secrets as $entry) {
                if ($this->verifyTotp($code, $entry['secret'])) {
                    $this->resetBruteForce();
                    Session::deleteValue(self::SESSION_KEY_PW);
                    Session::setValue(self::SESSION_KEY, true);
                    Redirect::internal('/system/settings');
                }
            }

            $this->recordFailedAttempt();
            return ['authenticated' => false, 'step' => 'totp', 'version' => $this->getVersion(),
                    'error' => 'Falscher Code. Bitte erneut versuchen.', 'csrfToken' => $csrfToken];
        }

        return ['authenticated' => false, 'step' => 'totp', 'version' => $this->getVersion(),
                'csrfToken' => $csrfToken];
    }

    /**
     * Forced first-time TOTP registration shown immediately after password login
     * when a multi-user account has no 2FA configured yet.
     *
     * @return array<string, mixed>
     */
    public function firstTotpSetup(): array {
        if ($this->isAuthenticated())
            Redirect::internal('/system/settings');

        $csrfToken = $this->getCsrfToken();

        if (Session::getValue(self::SESSION_KEY_PW) !== true)
            Redirect::internal('/system');

        $email = Session::getValue(self::SESSION_KEY_USER) ?? '';
        if (empty($email))
            Redirect::internal('/system');

        $newSecret = $this->generateTotpSecret();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
            if (!$this->verifyCsrfToken())
                return ['authenticated' => false, 'step' => 'totp_setup', 'version' => $this->getVersion(),
                        'error' => 'Ungültige Anfrage.', 'csrfToken' => $csrfToken,
                        'newTotpSecret' => $newSecret, 'totpOtpUri' => $this->buildTotpUri($newSecret)];

            $secret  = trim($_POST['totp_secret'] ?? '');
            $code    = trim($_POST['otp_code'] ?? '');
            $appName = trim($_POST['app_name'] ?? '') ?: 'Authenticator';
            $useSecret = $secret !== '' ? $secret : $newSecret;

            if ($secret === '' || !$this->verifyTotp($code, $secret))
                return ['authenticated' => false, 'step' => 'totp_setup', 'version' => $this->getVersion(),
                        'error' => 'Falscher Code. Bitte erneut versuchen.', 'csrfToken' => $csrfToken,
                        'newTotpSecret' => $useSecret, 'totpOtpUri' => $this->buildTotpUri($useSecret)];

            if (defined('VINOU_CONFIG_DIR')) {
                $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
                $config     = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
                foreach (($config['system']['users'] ?? []) as $idx => $user) {
                    if (strtolower(trim($user['email'] ?? '')) !== strtolower($email)) continue;
                    $existing   = $this->getUserTotpSecretsFromArray($user);
                    $existing[] = ['name' => $appName, 'secret' => $secret];
                    $this->setNestedValue($config, "system.users.{$idx}.totp_secrets", $existing);
                    file_put_contents($configFile, Yaml::dump($config, 10, 2));
                    break;
                }
            }

            Session::deleteValue(self::SESSION_KEY_PW);
            Session::setValue(self::SESSION_KEY, true);
            Redirect::internal('/system/settings');
        }

        return ['authenticated' => false, 'step' => 'totp_setup', 'version' => $this->getVersion(),
                'csrfToken' => $csrfToken, 'newTotpSecret' => $newSecret,
                'totpOtpUri' => $this->buildTotpUri($newSecret)];
    }

    // ─────────────────────── Panel sections ───────────────────────

    /**
     * Entry point for /system/{name} — serves Panel.twig with section data.
     *
     * @param array<int|string, mixed> $params  First element is the section slug.
     * @return array<string, mixed>
     */
    public function page(array $params = []): array {
        $csrfToken = $this->getCsrfToken();

        if (!$this->isAuthenticated())
            return ['authenticated' => false, 'csrfToken' => $csrfToken];

        $name  = $params[0] ?? 'settings';
        $valid = ['settings', 'routes', 'mail', 'system', 'redirects', 'users'];
        if (!in_array($name, $valid, true))
            $name = 'settings';

        $message = null;

        $flash  = $this->popFlash();
        $system = $this->settingsService->get('system') ?? [];

        if ($name === 'system') {
            $sectionData = [
                'sectionName'      => $name,
                'message'          => $message,
                'flash'            => $flash,
                'environment'      => $this->checkEnvironment(),
                'vinouPackages'    => $this->getVinouPackages(),
                'instagramStatus'  => $this->getInstagramStatus(),
            ];
        } elseif ($name === 'users') {
            $email     = Session::getValue(self::SESSION_KEY_USER) ?? '';
            $multiUser = !empty($system['users']) && is_array($system['users']);
            $existing  = $multiUser && $email !== '' ? $this->getUserTotpSecrets($system, $email) : [];
            $newSecret = $this->generateTotpSecret();
            $sectionData = [
                'sectionName'   => $name,
                'users'         => $this->getAdminUsers($system),
                'flash'         => $flash,
                'currentEmail'  => $email,
                'totpEnabled'   => !empty($existing),
                'totpApps'      => array_map(fn($i, $s) => ['index' => $i, 'name' => $s['name'] ?? 'App ' . ($i + 1)],
                                             array_keys($existing), $existing),
                'newTotpSecret' => $newSecret,
                'totpOtpUri'    => $this->buildTotpUri($newSecret),
            ];
        } else {
            $sectionData = match ($name) {
                'settings'  => ['sectionName' => $name, 'settings' => $this->getSettings(),
                                'projectPaths' => $this->getProjectSettingsPaths(), 'flash' => $flash],
                'routes'    => ['sectionName' => $name, 'routes' => $this->getRoutes()],
                'mail'      => ['sectionName' => $name, 'mailConfig' => $this->getMailConfig()],
                'redirects' => ['sectionName' => $name, 'redirects' => $this->getRedirects(), 'flash' => $flash],
                default     => ['sectionName' => $name],
            };
        }

        return array_merge(['authenticated' => true, 'adminTab' => $name, 'csrfToken' => $csrfToken], $sectionData);
    }

    // ─────────────────────── Settings CRUD ───────────────────────

    /**
     * Saves a single setting value to config/settings.yml at a dot-notation path.
     *
     * @return array<string, mixed>
     */
    public function saveSetting(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/settings'); exit;
        }

        $key = trim($_POST['key'] ?? '');
        $raw = $_POST['value'] ?? '';

        if ($key !== '' && !in_array(explode('.', $key)[0], self::SETTINGS_WHITELIST, true)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => "'{$key}' liegt außerhalb des editierbaren Bereichs."]);
            header('HX-Redirect: /system/settings'); exit;
        }

        if ($key === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Kein Schlüssel angegeben.']);
        } elseif (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
        } else {
            try { $value = Yaml::parse($raw); } catch (\Exception $e) { $value = $raw; }

            $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
            $this->setNestedValue($current, $key, $value);
            file_put_contents($configFile, Yaml::dump($current, 10, 2));
            Session::setValue('admin_flash', ['type' => 'success', 'message' => "'{$key}' gespeichert."]);
        }

        header('HX-Redirect: /system/settings'); exit;
    }

    /**
     * Removes a key at a dot-notation path from config/settings.yml.
     *
     * @return array<string, mixed>
     */
    public function deleteSetting(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/settings'); exit;
        }

        $path = trim($_POST['key'] ?? '');

        if ($path !== '' && !in_array(explode('.', $path)[0], self::SETTINGS_WHITELIST, true)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => "'{$path}' liegt außerhalb des editierbaren Bereichs."]);
            header('HX-Redirect: /system/settings'); exit;
        }

        if ($path === '' || !defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültiger Schlüssel.']);
        } else {
            $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
            $this->unsetNestedValue($current, $path);
            file_put_contents($configFile, Yaml::dump($current, 10, 2));
            Session::setValue('admin_flash', ['type' => 'success', 'message' => "'{$path}' gelöscht."]);
        }

        header('HX-Redirect: /system/settings'); exit;
    }

    /**
     * Appends a new element to an array or adds a key to an object in config/settings.yml.
     *
     * @return array<string, mixed>
     */
    public function addSetting(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/settings'); exit;
        }

        $path = trim($_POST['path'] ?? '');
        $key  = trim($_POST['key'] ?? '');
        $raw  = $_POST['value'] ?? '';

        if ($path !== '' && !in_array(explode('.', $path)[0], self::SETTINGS_WHITELIST, true)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => "'{$path}' liegt außerhalb des editierbaren Bereichs."]);
            header('HX-Redirect: /system/settings'); exit;
        }

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
        } else {
            try { $value = Yaml::parse($raw); } catch (\Exception $e) { $value = $raw; }

            $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
            $this->appendToPath($current, $path, $key, $value);
            file_put_contents($configFile, Yaml::dump($current, 10, 2));

            $label = $key !== '' ? "'{$path}.{$key}'" : "Element in '{$path}'";
            Session::setValue('admin_flash', ['type' => 'success', 'message' => "{$label} hinzugefügt."]);
        }

        header('HX-Redirect: /system/settings'); exit;
    }

    // ─────────────────────── Instagram ────────────────────────────

    /**
     * Saves the Meta App ID and App Secret to config/settings.yml.
     *
     * @return array<string, mixed>
     */
    public function saveInstagramCredentials(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }
        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/system'); exit;
        }
        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
            header('HX-Redirect: /system/system'); exit;
        }

        $appId     = trim($_POST['app_id']     ?? '');
        $appSecret = trim($_POST['app_secret'] ?? '');

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];

        if ($appId !== '')
            $current['instagram']['app_id'] = $appId;
        if ($appSecret !== '' && $appSecret !== '••••••••')
            $current['instagram']['app_secret'] = $appSecret;

        file_put_contents($configFile, Yaml::dump($current, 10, 2));
        Session::setValue('admin_flash', ['type' => 'success', 'message' => 'Instagram-App-Daten gespeichert.']);
        header('HX-Redirect: /system/system'); exit;
    }

    /**
     * HTMX endpoint: optionally performs a refresh or disconnect, then returns
     * the current Instagram connection status for InstagramStatus.twig.
     *
     * @return array<string, mixed>
     */
    public function instagramStatusAction(): array {
        if (!$this->isAuthenticated())
            return ['connected' => false, 'appConfigured' => false];

        $action = $_GET['action'] ?? null;

        if ($action === 'refresh') {
            $ig    = (array)($this->settingsService->get('instagram') ?? []);
            $token = $ig['access_token'] ?? '';
            if ($token) {
                $result = (new Instagram())->refreshToken($token);
                if (!empty($result['access_token'])) {
                    $this->writeInstagramToken([
                        'access_token'  => $result['access_token'],
                        'token_expires' => time() + (int)($result['expires_in'] ?? 5183944),
                    ]);
                }
            }
        } elseif ($action === 'disconnect') {
            $this->removeInstagramToken();
        }

        return $this->getInstagramStatus();
    }

    /**
     * @return array<string, mixed>
     */
    private function getInstagramStatus(): array {
        $ig    = [];
        if (defined('VINOU_CONFIG_DIR')) {
            $file = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
            if (is_file($file))
                $ig = (array)((Yaml::parseFile($file)['instagram']) ?? []);
        }
        $token   = $ig['access_token'] ?? null;
        $expires = isset($ig['token_expires']) ? (int)$ig['token_expires'] : null;

        if (!$token) {
            return [
                'connected'     => false,
                'appConfigured' => !empty($ig['app_id']) && !empty($ig['app_secret']),
                'appId'         => $ig['app_id'] ?? '',
            ];
        }

        $daysLeft = $expires !== null ? (int)round(($expires - time()) / 86400) : null;
        $statusKey = match (true) {
            $daysLeft === null  => 'unknown',
            $daysLeft <= 0      => 'expired',
            $daysLeft <= 7      => 'expiring',
            default             => 'valid',
        };

        return [
            'connected'     => true,
            'statusKey'     => $statusKey,
            'daysLeft'      => $daysLeft,
            'username'      => $ig['username'] ?? '',
            'userId'        => $ig['user_id']  ?? '',
            'appId'         => $ig['app_id']   ?? '',
            'appConfigured' => !empty($ig['app_id']) && !empty($ig['app_secret']),
        ];
    }

    private function writeInstagramToken(array $data): void {
        if (!defined('VINOU_CONFIG_DIR')) return;
        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        foreach ($data as $k => $v)
            $current['instagram'][$k] = $v;
        file_put_contents($configFile, Yaml::dump($current, 10, 2));
    }

    private function removeInstagramToken(): void {
        if (!defined('VINOU_CONFIG_DIR')) return;
        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        foreach (['access_token', 'token_expires', 'user_id', 'username'] as $k)
            unset($current['instagram'][$k]);
        file_put_contents($configFile, Yaml::dump($current, 10, 2));
    }

    private function buildInstagramRedirectUri(): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/system?action=instagram_callback';
    }

    // ─────────────────────── Redirects CRUD ───────────────────────

    /**
     * Adds or updates a redirect in config/redirects.yml.
     *
     * @return array<string, mixed>
     */
    public function saveRedirect(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/redirects'); exit;
        }

        $source         = trim($_POST['source'] ?? '', '/');
        $originalSource = trim($_POST['original_source'] ?? '', '/');
        $target         = trim($_POST['redirect'] ?? '');
        $method         = in_array($_POST['method'] ?? '', ['get', 'post', 'all'], true) ? $_POST['method'] : 'get';

        if ($source === '' || $target === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Quell-Pfad und Ziel-URL sind erforderlich.']);
            header('HX-Redirect: /system/redirects'); exit;
        }

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
            header('HX-Redirect: /system/redirects'); exit;
        }

        $current = $this->getRedirects();

        if ($originalSource !== '' && $originalSource !== $source)
            $current = array_values(array_filter($current, fn($r) => $r['source'] !== $originalSource));

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
        header('HX-Redirect: /system/redirects'); exit;
    }

    /**
     * Deletes a redirect from config/redirects.yml by its source path.
     *
     * @return array<string, mixed>
     */
    public function deleteRedirect(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/redirects'); exit;
        }

        $source = trim($_POST['source'] ?? '', '/');

        if ($source === '' || !defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültiger Quell-Pfad.']);
            header('HX-Redirect: /system/redirects'); exit;
        }

        $current  = $this->getRedirects();
        $filtered = array_filter($current, fn($r) => $r['source'] !== $source);
        $this->saveRedirectsFile(array_values($filtered));
        Session::setValue('admin_flash', ['type' => 'success', 'message' => "Redirect '/{$source}' gelöscht."]);
        header('HX-Redirect: /system/redirects'); exit;
    }

    // ─────────────────────── TOTP management ───────────────────────

    /**
     * Enables TOTP 2FA: verifies the submitted code against the submitted secret, then saves.
     *
     * @return array<string, mixed>
     */
    public function setupTotp(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false, 'csrfToken' => $this->getCsrfToken()];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/users'); exit;
        }

        $secret  = trim($_POST['totp_secret'] ?? '');
        $code    = trim($_POST['otp_code'] ?? '');
        $appName = trim($_POST['app_name'] ?? '') ?: 'Authenticator';

        if ($secret === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Kein Secret übermittelt.']);
            header('HX-Redirect: /system/users'); exit;
        }

        if (!$this->verifyTotp($code, $secret)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Falscher Bestätigungscode. Bitte lade die Seite neu und versuche es erneut.']);
            header('HX-Redirect: /system/users'); exit;
        }

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        $systemData = $current['system'] ?? [];
        $multiUser  = !empty($systemData['users']) && is_array($systemData['users']);

        if ($multiUser) {
            $email = strtolower(Session::getValue(self::SESSION_KEY_USER) ?? '');
            foreach (($current['system']['users'] ?? []) as $idx => $user) {
                if (strtolower(trim($user['email'] ?? '')) !== $email) continue;
                $existing   = $this->getUserTotpSecretsFromArray($user);
                $existing[] = ['name' => $appName, 'secret' => $secret];
                $this->setNestedValue($current, "system.users.{$idx}.totp_secrets", $existing);
                break;
            }
        } else {
            // Legacy: save to system.totp_secrets, migrate single-secret format
            $existing = $this->getTotpSecrets($systemData);
            $this->unsetNestedValue($current, 'system.totp_secret');
            $existing[] = ['name' => $appName, 'secret' => $secret];
            $this->setNestedValue($current, 'system.totp_secrets', $existing);
        }
        file_put_contents($configFile, Yaml::dump($current, 10, 2));

        Session::setValue('admin_flash', ['type' => 'success', 'message' => "App \"{$appName}\" als 2FA-Gerät hinzugefügt."]);
        header('HX-Redirect: /system/users'); exit;
    }

    /**
     * Disables TOTP 2FA by removing system.totp_secret from settings.yml.
     *
     * @return array<string, mixed>
     */
    public function disableTotp(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false, 'csrfToken' => $this->getCsrfToken()];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/users'); exit;
        }

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        $systemData = $current['system'] ?? [];
        $multiUser  = !empty($systemData['users']) && is_array($systemData['users']);
        $appIdx     = (int)($_POST['totp_index'] ?? -1);
        $msg        = '2FA deaktiviert.';

        if ($multiUser) {
            $email = strtolower(Session::getValue(self::SESSION_KEY_USER) ?? '');
            foreach (($current['system']['users'] ?? []) as $userIdx => $user) {
                if (strtolower(trim($user['email'] ?? '')) !== $email) continue;
                $existing = $this->getUserTotpSecretsFromArray($user);
                if (isset($existing[$appIdx])) {
                    $removed = $existing[$appIdx]['name'] ?? 'App';
                    array_splice($existing, $appIdx, 1);
                } else {
                    $removed  = 'App';
                    $existing = [];
                }
                if (empty($existing)) {
                    $this->unsetNestedValue($current, "system.users.{$userIdx}.totp_secrets");
                    $msg = '2FA vollständig deaktiviert.';
                } else {
                    $this->setNestedValue($current, "system.users.{$userIdx}.totp_secrets", $existing);
                    $msg = "\"{$removed}\" entfernt. " . count($existing) . ' App(s) noch registriert.';
                }
                break;
            }
        } else {
            $existing = $this->getTotpSecrets($systemData);
            if (isset($existing[$appIdx])) {
                $removed = $existing[$appIdx]['name'] ?? 'App';
                array_splice($existing, $appIdx, 1);
            } else {
                $removed  = 'App';
                $existing = [];
            }
            $this->unsetNestedValue($current, 'system.totp_secret');
            if (empty($existing)) {
                $this->unsetNestedValue($current, 'system.totp_secrets');
                $msg = '2FA vollständig deaktiviert.';
            } else {
                $this->setNestedValue($current, 'system.totp_secrets', $existing);
                $msg = "\"{$removed}\" entfernt. " . count($existing) . ' App(s) noch registriert.';
            }
        }
        file_put_contents($configFile, Yaml::dump($current, 10, 2));

        Session::setValue('admin_flash', ['type' => 'success', 'message' => $msg]);
        header('HX-Redirect: /system/users'); exit;
    }

    // ─────────────────────── Security helpers ───────────────────────

    /**
     * Returns or generates the per-session CSRF token.
     */
    private function getCsrfToken(): string {
        $token = Session::getValue(self::SESSION_KEY_CSRF);
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            Session::setValue(self::SESSION_KEY_CSRF, $token);
        }
        return $token;
    }

    /**
     * Verifies CSRF token from HTMX header (X-CSRF-Token) or POST field (_csrf_token).
     */
    private function verifyCsrfToken(): bool {
        $sessionToken = Session::getValue(self::SESSION_KEY_CSRF);
        if (empty($sessionToken)) return false;
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf_token'] ?? '';
        return !empty($submitted) && hash_equals($sessionToken, $submitted);
    }

    /**
     * Returns null if login is allowed, or a lockout message if the limit is exceeded.
     */
    private function checkBruteForce(): ?string {
        $bf          = Session::getValue(self::SESSION_KEY_BF) ?? ['count' => 0, 'locked_until' => 0];
        $lockedUntil = (int)($bf['locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            $mins = (int)ceil(($lockedUntil - time()) / 60);
            return "Zu viele Fehlversuche. Bitte warte noch {$mins} Minute(n).";
        }
        return null;
    }

    /**
     * Increments the failed-attempt counter; locks after BF_MAX_ATTEMPTS.
     */
    private function recordFailedAttempt(): void {
        $bf    = Session::getValue(self::SESSION_KEY_BF) ?? ['count' => 0, 'locked_until' => 0];
        $count = (int)($bf['count'] ?? 0) + 1;
        Session::setValue(self::SESSION_KEY_BF, [
            'count'        => $count,
            'locked_until' => $count >= self::BF_MAX_ATTEMPTS ? time() + self::BF_LOCKOUT_SECS : 0,
        ]);
    }

    /**
     * Clears the brute-force counter after a successful login.
     */
    private function resetBruteForce(): void {
        Session::deleteValue(self::SESSION_KEY_BF);
    }

    /**
     * Verifies the entered password against the stored value (bcrypt or plaintext).
     */
    private function verifyPassword(string $input, string $stored): bool {
        if (!$this->isPlaintext($stored))
            return password_verify($input, $stored);
        return hash_equals($stored, $input);
    }

    private function isPlaintext(string $stored): bool {
        return !str_starts_with($stored, '$2y$') && !str_starts_with($stored, '$2b$');
    }

    /**
     * Saves a bcrypt hash at a dot-notation path in config/settings.yml.
     * Called after a successful plaintext login to auto-upgrade the stored password.
     */
    private function upgradePasswordHash(string $input, string $path): void {
        if (!defined('VINOU_CONFIG_DIR')) return;
        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        if (!is_file($configFile)) return;
        $config = Yaml::parseFile($configFile) ?? [];
        $this->setNestedValue($config, $path, password_hash($input, PASSWORD_BCRYPT));
        file_put_contents($configFile, Yaml::dump($config, 10, 2));
    }

    // ─────────────────────── TOTP (RFC 6238) ───────────────────────

    /**
     * Returns all registered TOTP secrets as a normalized list.
     *
     * Supports both the legacy single-secret format (system.totp_secret) and
     * the current multi-secret format (system.totp_secrets).
     *
     * @param  array<string, mixed> $system  Contents of the 'system' settings key.
     * @return list<array{name: string, secret: string}>
     */
    private function getTotpSecrets(array $system): array {
        // New multi-secret format
        if (!empty($system['totp_secrets']) && is_array($system['totp_secrets'])) {
            return array_values(array_filter($system['totp_secrets'],
                fn($s) => is_array($s) && !empty($s['secret'])));
        }
        // Legacy single-secret format
        if (!empty($system['totp_secret']) && is_string($system['totp_secret'])) {
            return [['name' => 'Standard', 'secret' => $system['totp_secret']]];
        }
        return [];
    }

    /**
     * Returns a specific user's TOTP secrets from system.users by email.
     *
     * @param  array<string, mixed> $system
     * @return list<array{name: string, secret: string}>
     */
    private function getUserTotpSecrets(array $system, string $email): array {
        if (empty($system['users'])) return [];
        $email = strtolower(trim($email));
        foreach ($system['users'] as $user) {
            if (!is_array($user)) continue;
            if (strtolower(trim($user['email'] ?? '')) !== $email) continue;
            return $this->getUserTotpSecretsFromArray($user);
        }
        return [];
    }

    /**
     * Extracts and normalizes totp_secrets from a single user array entry.
     *
     * @param  array<string, mixed> $user
     * @return list<array{name: string, secret: string}>
     */
    private function getUserTotpSecretsFromArray(array $user): array {
        if (empty($user['totp_secrets']) || !is_array($user['totp_secrets'])) return [];
        return array_values(array_filter($user['totp_secrets'],
            fn($s) => is_array($s) && !empty($s['secret'])));
    }

    /**
     * Decodes a base32-encoded string to raw bytes.
     */
    private function base32Decode(string $b32): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32      = strtoupper(rtrim($b32, '='));
        $bits     = '';
        for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos !== false)
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $raw = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8)
            $raw .= chr(bindec(substr($bits, $i, 8)));
        return $raw;
    }

    /**
     * Generates a fresh 20-byte TOTP secret, base32-encoded.
     */
    private function generateTotpSecret(): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes    = random_bytes(20);
        $bits     = '';
        for ($i = 0; $i < 20; $i++)
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        $secret = '';
        for ($i = 0; $i + 5 <= strlen($bits); $i += 5)
            $secret .= $alphabet[bindec(substr($bits, $i, 5))];
        return $secret;
    }

    /**
     * Verifies a 6-digit TOTP code. Accepts ±1 window to tolerate clock drift.
     */
    private function verifyTotp(string $code, string $secret): bool {
        $key     = $this->base32Decode($secret);
        $counter = (int)floor(time() / 30);
        $code    = str_pad($code, 6, '0', STR_PAD_LEFT);

        for ($delta = -1; $delta <= 1; $delta++) {
            $msg  = pack('J', $counter + $delta);
            $hash = hash_hmac('sha1', $msg, $key, true);
            $off  = ord($hash[19]) & 0xf;
            $n    = ((ord($hash[$off])     & 0x7f) << 24)
                  | ((ord($hash[$off + 1]) & 0xff) << 16)
                  | ((ord($hash[$off + 2]) & 0xff) <<  8)
                  |  (ord($hash[$off + 3]) & 0xff);
            if (str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT) === $code)
                return true;
        }
        return false;
    }

    /**
     * Builds an otpauth:// URI for authenticator app import / QR code.
     *
     * Label format: "host:Admin" so the authenticator app shows the domain.
     */
    private function buildTotpUri(string $secret): string {
        $host   = $_SERVER['HTTP_HOST'] ?? 'vinou-admin';
        $label  = rawurlencode($host . ':Admin');
        $issuer = rawurlencode($host);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    // ─────────────────────── Session helpers ───────────────────────

    private function isAuthenticated(): bool {
        return Session::getValue(self::SESSION_KEY) === true;
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

    // ─────────────────────── Data helpers ───────────────────────

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
     * @return array<string, mixed>
     */
    private function getSettings(): array {
        $settings = $this->settingsService->getAll() ?? [];
        return array_intersect_key($settings, array_flip(self::SETTINGS_WHITELIST));
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoutes(): array {
        return $this->routeConfig->getEnabledConfiguration();
    }

    /**
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
            $smtp = $config['smtp'];
            $config['smtp']['reachable'] = $this->checkSmtp($smtp['host'] ?? '', (int)($smtp['port'] ?? 25));
        }

        $includeDefaults = !isset($config['template']['includeDefaults']) || $config['template']['includeDefaults'] !== false;
        $config['_templateDirs'] = $this->resolveMailTemplateDirs($config, $includeDefaults);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{path: string, source: string, templates: list<string>}>
     */
    private function resolveMailTemplateDirs(array $config, bool $includeDefaults): array {
        $dirs = [];

        if (isset($config['template']['rootDir'], $config['template']['directories'])) {
            $rootDir = Helper::getNormDocRoot() . $config['template']['rootDir'];
            foreach ((array)$config['template']['directories'] as $dir) {
                $path = $rootDir . $dir;
                $twig = glob($path . '*.twig') ?: [];
                if (is_dir($path) && !empty($twig))
                    $dirs[] = ['path' => $path, 'source' => 'project', 'templates' => array_map('basename', $twig)];
            }
        }

        if ($includeDefaults && !is_null($this->themeDir)) {
            $path = Helper::getNormDocRoot() . $this->themeDir . 'Resources/Mail/';
            $twig = glob($path . '*.twig') ?: [];
            if (is_dir($path) && !empty($twig))
                $dirs[] = ['path' => $path, 'source' => 'theme', 'templates' => array_map('basename', $twig)];
        }

        if ($includeDefaults) {
            $path = realpath(__DIR__ . '/../../Resources/Mail/');
            if ($path) {
                $twig = glob($path . '/*.twig') ?: [];
                if (!empty($twig))
                    $dirs[] = ['path' => $path . '/', 'source' => 'default', 'templates' => array_map('basename', $twig)];
            }
        }

        return $dirs;
    }

    private function checkSmtp(string $host, int $port): bool {
        if (empty($host)) return false;
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) { fclose($connection); return true; }
        return false;
    }

    /**
     * @return array<string, array{available: bool, label: string, detail: string}>
     */
    private function checkEnvironment(): array {
        $cacheRoot = Helper::getNormDocRoot() . 'Cache/';
        $vinou     = $this->settingsService->get('vinou') ?? [];
        $hasApiCfg = !empty($vinou['token']) && !empty($vinou['authid']);
        return [
            'vinou_api'       => ['label' => 'Vinou API Zugangsdaten',   'available' => $hasApiCfg,  'detail' => $hasApiCfg ? 'authid + token konfiguriert' : 'Fehlt in config/settings.yml (vinou.authid / vinou.token)'],
            'php'             => ['label' => 'PHP Version',              'available' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => PHP_VERSION],
            'curl'            => ['label' => 'cURL',                     'available' => extension_loaded('curl'),    'detail' => extension_loaded('curl') ? curl_version()['version'] : 'nicht verfügbar'],
            'gd'              => ['label' => 'GD Library',               'available' => extension_loaded('gd'),      'detail' => extension_loaded('gd') ? (gd_info()['GD Version'] ?? 'vorhanden') : 'nicht verfügbar'],
            'imagick'         => ['label' => 'ImageMagick (Imagick)',     'available' => extension_loaded('imagick'), 'detail' => extension_loaded('imagick') ? (new \Imagick())->getVersion()['versionString'] ?? 'vorhanden' : 'nicht verfügbar'],
            'intl'            => ['label' => 'Intl',                     'available' => extension_loaded('intl'),    'detail' => extension_loaded('intl') ? INTL_ICU_VERSION : 'nicht verfügbar'],
            'mbstring'        => ['label' => 'Mbstring',                 'available' => extension_loaded('mbstring'),'detail' => extension_loaded('mbstring') ? 'vorhanden' : 'nicht verfügbar'],
            'allow_url_fopen' => ['label' => 'allow_url_fopen',          'available' => (bool)ini_get('allow_url_fopen'), 'detail' => ini_get('allow_url_fopen') ? 'aktiv' : 'deaktiviert'],
            'cache_images'    => ['label' => 'Cache/Images schreibbar',  'available' => is_writable($cacheRoot . 'Images'), 'detail' => is_dir($cacheRoot . 'Images') ? ($this->isWritable($cacheRoot . 'Images') ? 'schreibbar' : 'nicht schreibbar') : 'Ordner fehlt'],
            'cache_twig'      => ['label' => 'Cache/Twig schreibbar',    'available' => is_writable($cacheRoot . 'Twig'),   'detail' => is_dir($cacheRoot . 'Twig')   ? ($this->isWritable($cacheRoot . 'Twig')   ? 'schreibbar' : 'nicht schreibbar') : 'Ordner fehlt'],
        ];
    }

    // ─────────────────────── Admin users ───────────────────────

    /**
     * Returns registered admin users with email and 2FA app count (no password hashes exposed).
     *
     * @return list<array{email: string, totpCount: int}>
     */
    private function getAdminUsers(array $system): array {
        if (empty($system['users']) || !is_array($system['users'])) return [];
        return array_values(array_map(
            fn($u) => [
                'email'     => $u['email'] ?? '',
                'totpCount' => count($this->getUserTotpSecretsFromArray($u)),
            ],
            array_filter($system['users'], fn($u) => is_array($u) && !empty($u['email']))
        ));
    }

    /**
     * Adds a new admin user to system.users in config/settings.yml.
     *
     * @return array<string, mixed>
     */
    public function saveUser(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/users'); exit;
        }

        $email   = strtolower(trim($_POST['email'] ?? ''));
        $pw      = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige E-Mail-Adresse.']);
            header('HX-Redirect: /system/users'); exit;
        }
        if (strlen($pw) < 10) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Passwort muss mindestens 10 Zeichen haben.']);
            header('HX-Redirect: /system/users'); exit;
        }
        if ($pw !== $confirm) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Passwörter stimmen nicht überein.']);
            header('HX-Redirect: /system/users'); exit;
        }

        if (!defined('VINOU_CONFIG_DIR')) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'VINOU_CONFIG_DIR nicht definiert.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        $users      = $current['system']['users'] ?? [];

        foreach ($users as $u) {
            if (strtolower(trim($u['email'] ?? '')) === $email) {
                Session::setValue('admin_flash', ['type' => 'error', 'message' => "'{$email}' ist bereits registriert."]);
                header('HX-Redirect: /system/users'); exit;
            }
        }

        $users[] = ['email' => $email, 'password' => password_hash($pw, PASSWORD_BCRYPT)];
        $this->setNestedValue($current, 'system.users', $users);
        file_put_contents($configFile, Yaml::dump($current, 10, 2));

        Session::setValue('admin_flash', ['type' => 'success', 'message' => "'{$email}' hinzugefügt."]);
        header('HX-Redirect: /system/users'); exit;
    }

    /**
     * Updates an existing admin user's email and/or password.
     *
     * @return array<string, mixed>
     */
    public function updateUser(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/users'); exit;
        }

        $originalEmail = strtolower(trim($_POST['original_email'] ?? ''));
        $newEmail      = strtolower(trim($_POST['email'] ?? ''));
        $pw            = $_POST['password'] ?? '';
        $confirm       = $_POST['password_confirm'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige E-Mail-Adresse.']);
            header('HX-Redirect: /system/users'); exit;
        }
        if ($pw !== '' && strlen($pw) < 10) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Passwort muss mindestens 10 Zeichen haben.']);
            header('HX-Redirect: /system/users'); exit;
        }
        if ($pw !== '' && $pw !== $confirm) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Passwörter stimmen nicht überein.']);
            header('HX-Redirect: /system/users'); exit;
        }

        if (!defined('VINOU_CONFIG_DIR') || $originalEmail === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        $users      = $current['system']['users'] ?? [];

        if ($newEmail !== $originalEmail) {
            foreach ($users as $u) {
                if (strtolower(trim($u['email'] ?? '')) === $newEmail) {
                    Session::setValue('admin_flash', ['type' => 'error', 'message' => "'{$newEmail}' ist bereits vergeben."]);
                    header('HX-Redirect: /system/users'); exit;
                }
            }
        }

        $found = false;
        foreach ($users as &$user) {
            if (strtolower(trim($user['email'] ?? '')) !== $originalEmail) continue;
            $user['email'] = $newEmail;
            if ($pw !== '') $user['password'] = password_hash($pw, PASSWORD_BCRYPT);
            $found = true;
            break;
        }
        unset($user);

        if (!$found) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Benutzer nicht gefunden.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $this->setNestedValue($current, 'system.users', $users);
        file_put_contents($configFile, Yaml::dump($current, 10, 2));

        Session::setValue('admin_flash', ['type' => 'success', 'message' => 'Benutzer aktualisiert.']);
        header('HX-Redirect: /system/users'); exit;
    }

    /**
     * Removes an admin user from system.users by email address.
     *
     * @return array<string, mixed>
     */
    public function deleteUser(): array {
        if (!$this->isAuthenticated()) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) { header('HX-Redirect: /system'); exit; }
            return ['authenticated' => false];
        }

        if (!$this->verifyCsrfToken()) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage (CSRF).']);
            header('HX-Redirect: /system/users'); exit;
        }

        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!defined('VINOU_CONFIG_DIR') || $email === '') {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Ungültige Anfrage.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        $current    = is_file($configFile) ? (Yaml::parseFile($configFile) ?? []) : [];
        $users      = $current['system']['users'] ?? [];
        $filtered   = array_values(array_filter($users, fn($u) => strtolower(trim($u['email'] ?? '')) !== $email));

        if (empty($filtered)) {
            Session::setValue('admin_flash', ['type' => 'error', 'message' => 'Der letzte Benutzer kann nicht gelöscht werden.']);
            header('HX-Redirect: /system/users'); exit;
        }

        $this->setNestedValue($current, 'system.users', $filtered);
        file_put_contents($configFile, Yaml::dump($current, 10, 2));

        Session::setValue('admin_flash', ['type' => 'success', 'message' => "'{$email}' entfernt."]);
        header('HX-Redirect: /system/users'); exit;
    }

    /**
     * Returns all installed vinou/* packages with their versions.
     *
     * @return list<array{name: string, version: string}>
     */
    private function getVinouPackages(): array {
        if (!class_exists(\Composer\InstalledVersions::class))
            return [];

        $packages = [];
        foreach (\Composer\InstalledVersions::getInstalledPackages() as $package) {
            if (!str_starts_with($package, 'vinou/')) continue;
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion($package) ?? 'dev';
            } catch (\Exception) {
                $version = 'dev';
            }
            $packages[] = ['name' => $package, 'version' => $version];
        }
        usort($packages, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $packages;
    }

    /**
     * Fragment endpoint for in-place cache actions (clear + warmup) and API diagnostics.
     * Returns either array{message: string, error: bool} for cache actions or
     * array{checks: list<array{label: string, ok: bool, detail: string}>, error: bool} for testApi.
     */
    public function cacheAction(): array {
        if (!$this->isAuthenticated())
            return ['message' => 'Nicht authentifiziert.', 'error' => true];

        $action = $_GET['action'] ?? '';

        if ($action === 'testApi')
            return $this->runApiDiagnostics();

        $message = match ($action) {
            'clearImages'    => $this->clearCache('Images'),
            'clearInstagram' => (new Instagram())->clearCache(),
            'clearPdf'       => $this->clearCache('PDF'),
            'warmupImages'   => $this->warmupImages(),
            'warmupPdf'      => $this->warmupPdfs(),
            default          => 'Unbekannte Aktion: ' . htmlspecialchars($action),
        };

        return ['message' => $message, 'error' => str_starts_with($message, 'Fehler')];
    }

    private function buildApi(): Api|string {
        $vinou  = $this->settingsService->get('vinou') ?? [];
        $token  = $vinou['token']  ?? null;
        $authid = $vinou['authid'] ?? null;

        if (!$token || !$authid)
            return 'Fehler: Vinou-API-Zugangsdaten nicht konfiguriert.';

        set_time_limit(120);
        $api = new Api($token, $authid);

        if (!$api->connected)
            return 'Fehler: Keine Verbindung zur Vinou-API.';

        return $api;
    }

    private function warmupImages(): string {
        $api = $this->buildApi();
        if (is_string($api)) return $api;

        $system = $this->settingsService->get('system') ?? [];

        $winesResult    = $api->getWinesAll(['pageSize' => 500]);
        $bundlesResult  = $api->getBundlesAll(['pageSize' => 500]);
        $productsResult = $api->getProductsAll(['pageSize' => 500]);

        $wines    = is_array($winesResult['wines']  ?? null) ? $winesResult['wines']  : (is_array($winesResult)    ? $winesResult    : []);
        $bundles  = is_array($bundlesResult['data'] ?? null) ? $bundlesResult['data'] : (is_array($bundlesResult)  ? $bundlesResult  : []);
        $products = is_array($productsResult)                 ? $productsResult        : [];

        $count  = 0;
        $errors = 0;
        $settingsArr = ['system' => $system];

        foreach (array_merge($wines, $bundles, $products) as $item) {
            $src = $item['image'] ?? null;
            if (empty($src)) continue;

            $result = Images::storeApiImage($src, $item['chstamp'] ?? null);

            if (isset($result['absolute']) && is_file($result['absolute'])) {
                $ext = strtolower(pathinfo($result['absolute'], PATHINFO_EXTENSION));
                if (ImageService::isWebPAllowed($settingsArr)
                    && ImageService::checkWebPEnvironment()
                    && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])
                ) {
                    $webpPath = ImageService::replaceExtension($result['absolute'], 'webp');
                    if (!is_file($webpPath))
                        ImageService::convertToWebP($result['absolute'], $webpPath);
                }
                $count++;
            } else {
                $errors++;
            }
        }

        $msg = 'Bildcache: ' . $count . ' ' . ($count === 1 ? 'Bild' : 'Bilder') . ' gecached';
        if ($errors > 0) $msg .= ', ' . $errors . ' Fehler';
        return $msg . '.';
    }

    private function warmupPdfs(): string {
        $api = $this->buildApi();
        if (is_string($api)) return $api;

        $winesResult = $api->getWinesAll(['pageSize' => 500]);
        $wines = is_array($winesResult['wines'] ?? null) ? $winesResult['wines'] : (is_array($winesResult) ? $winesResult : []);

        $count  = 0;
        $errors = 0;

        foreach ($wines as $wine) {
            if (empty($wine['pdf'])) continue;
            $src    = '/PDF/' . $wine['id'] . '/' . $wine['pdf'];
            $result = Pdf::storeApiPDF($src, $wine['chstamp'] ?? null);
            !empty($result['src']) ? $count++ : $errors++;
        }

        $msg = 'PDF-Cache: ' . $count . ' ' . ($count === 1 ? 'PDF' : 'PDFs') . ' gecached';
        if ($errors > 0) $msg .= ', ' . $errors . ' Fehler';
        return $msg . '.';
    }

    private function runApiDiagnostics(): array {
        $vinou  = $this->settingsService->get('vinou') ?? [];
        $token  = $vinou['token']  ?? null;
        $authid = $vinou['authid'] ?? null;

        if (!$token || !$authid)
            return ['checks' => [], 'message' => 'Zugangsdaten fehlen (vinou.token / vinou.authid).', 'error' => true];

        set_time_limit(120);
        $t0  = microtime(true);
        $api = new Api($token, $authid);
        $ms  = (int)round((microtime(true) - $t0) * 1000);

        if (!$api->connected)
            return ['checks' => [], 'message' => 'Keine Verbindung zur Vinou-API (' . $ms . ' ms).', 'error' => true];

        $checks   = [];
        $anyError = false;

        // 1. Wines
        $t0     = microtime(true);
        $result = $api->getWinesAll(['pageSize' => 500]);
        $ms     = (int)round((microtime(true) - $t0) * 1000);
        $wines  = is_array($result['wines'] ?? null) ? $result['wines'] : (is_array($result) ? $result : []);
        $count  = count($wines);
        $ok     = $count > 0;
        if (!$ok) $anyError = true;
        $checks[] = ['label' => 'Weine', 'ok' => $ok, 'detail' => $count . ' Einträge (' . $ms . ' ms)'];

        // 2. Bundles
        $t0     = microtime(true);
        $result = $api->getBundlesAll(['pageSize' => 500]);
        $ms     = (int)round((microtime(true) - $t0) * 1000);
        $count  = is_array($result['data'] ?? null) ? count($result['data']) : 0;
        $checks[] = ['label' => 'Weinpakete', 'ok' => true, 'detail' => $count . ' Einträge (' . $ms . ' ms)'];

        // 3. Products
        $t0     = microtime(true);
        $result = $api->getProductsAll(['pageSize' => 500]);
        $ms     = (int)round((microtime(true) - $t0) * 1000);
        $count  = is_array($result) ? count($result) : 0;
        $checks[] = ['label' => 'Produkte', 'ok' => true, 'detail' => $count . ' Einträge (' . $ms . ' ms)'];

        // 4. Basket
        $t0   = microtime(true);
        $ok   = $api->createBasket();
        $ms   = (int)round((microtime(true) - $t0) * 1000);
        if (!$ok) $anyError = true;
        $checks[] = ['label' => 'Basket', 'ok' => (bool)$ok, 'detail' => $ok ? 'Basket erstellt (' . $ms . ' ms)' : 'Nicht erstellt (' . $ms . ' ms)'];

        // 5. Customer — error only when request fails or response has no id
        $t0       = microtime(true);
        $customer = $api->getCustomer();
        $ms       = (int)round((microtime(true) - $t0) * 1000);
        $hasData  = is_array($customer) && isset($customer['id']);
        if (!$hasData) $anyError = true;
        $checks[] = ['label' => 'Kunde', 'ok' => $hasData, 'detail' => $hasData ? (($customer['email'] ?? 'ID ' . $customer['id']) . ' (' . $ms . ' ms)') : 'Keine Daten (' . $ms . ' ms)'];

        return ['checks' => $checks, 'error' => $anyError, 'customer' => is_array($customer) ? $customer : null];
    }

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
            if ($entry->isFile()) { unlink($entry->getPathname()); $count++; }
        }
        return $type . ' Cache geleert (' . $count . ' ' . ($count === 1 ? 'Datei' : 'Dateien') . ' gelöscht).';
    }

    private function isWritable(string $dir): bool {
        return is_dir($dir) && is_writable($dir);
    }

    // ─────────────────────── Redirects helpers ───────────────────────

    /**
     * @return list<array{source: string, redirect: string, method: string}>
     */
    private function getRedirects(): array {
        if (!defined('VINOU_CONFIG_DIR')) return [];

        $file = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'redirects.yml';
        if (!is_file($file)) return [];

        $raw    = Yaml::parseFile($file) ?? [];
        $result = [];
        foreach ($raw as $source => $config) {
            if (!is_array($config) || ($config['type'] ?? '') !== 'redirect') continue;
            $result[] = ['source' => (string)$source, 'redirect' => $config['redirect'] ?? '', 'method' => $config['method'] ?? 'get'];
        }
        return $result;
    }

    /**
     * @param list<array{source: string, redirect: string, method: string}> $redirects
     */
    private function saveRedirectsFile(array $redirects): void {
        $out = [];
        foreach ($redirects as $r) {
            $source = trim($r['source'], '/');
            if ($source === '' || ($r['redirect'] ?? '') === '') continue;
            $out[$source] = ['type' => 'redirect', 'redirect' => $r['redirect'], 'method' => $r['method'] ?? 'get'];
        }
        $file = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'redirects.yml';
        file_put_contents($file, empty($out) ? "{}\n" : Yaml::dump($out, 3, 2));
    }

    // ─────────────────────── Array utilities ───────────────────────

    /**
     * @return list<string>
     */
    private function getProjectSettingsPaths(): array {
        if (!defined('VINOU_CONFIG_DIR')) return [];
        $configFile = Helper::getNormDocRoot() . VINOU_CONFIG_DIR . 'settings.yml';
        if (!is_file($configFile)) return [];
        return $this->flattenKeys(Yaml::parseFile($configFile) ?? []);
    }

    /**
     * @param array<mixed, mixed> $array
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

    private function unsetNestedValue(array &$array, string $path): void {
        $keys    = explode('.', $path);
        $lastKey = array_pop($keys);
        $lastKey = ctype_digit($lastKey) ? (int)$lastKey : $lastKey;
        $current = &$array;
        foreach ($keys as $key) {
            $key = ctype_digit($key) ? (int)$key : $key;
            if (!isset($current[$key]) || !is_array($current[$key])) return;
            $current = &$current[$key];
        }
        unset($current[$lastKey]);
    }

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
        if ($key === '') { $current[] = $value; } else { $current[$key] = $value; }
    }
}
