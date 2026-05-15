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

    public function __construct(DynamicRoutes &$routeConfig) {
        $this->settingsService = ServiceLocator::get('Settings');
        $this->routeConfig     = $routeConfig;
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
        if ($name === 'system' && ($_GET['action'] ?? '') === 'clearCache')
            $message = $this->clearCache($_GET['type'] ?? '');

        $flash  = $this->popFlash();
        $system = $this->settingsService->get('system') ?? [];

        if ($name === 'system') {
            $sectionData = [
                'sectionName'   => $name,
                'message'       => $message,
                'flash'         => $flash,
                'environment'   => $this->checkEnvironment(),
                'vinouPackages' => $this->getVinouPackages(),
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
        return $this->routeConfig->configuration;
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

        return $config;
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
        return [
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
