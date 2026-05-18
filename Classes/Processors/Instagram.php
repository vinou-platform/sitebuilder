<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Services\ServiceLocator;
use \Vinou\ApiConnector\Tools\Helper;
use \Monolog\Logger;
use \Monolog\Handler\RotatingFileHandler;

/**
 * Fetches and caches an Instagram media feed via the Instagram Graph API
 * (Instagram API with Instagram Login — the post-December-2024 standard).
 *
 * Token lifecycle:
 *   1. Admin connects via OAuth → short-lived token (1h)
 *   2. Short-lived token exchanged for long-lived token (60 days)
 *   3. Long-lived token refreshed before expiry (stays alive indefinitely)
 *
 * Tokens and app credentials are stored in config/settings.yml under the
 * top-level 'instagram' key. Cache lives in Cache/Instagram/feed.json.
 */
class Instagram implements ProcessorInterface {

    private string $cacheDir;
    private ?Logger $logger = null;

    public function __construct() {
        $this->initCacheDir();
        $this->initLogging();
    }

    // ─────────────────────── DataProcessing entry point ───────────────────────

    /**
     * Main dataProcessing method. Reads the access token from settings,
     * returns a cached or freshly-fetched array of media items.
     *
     * @param array<string, mixed> $params  limit (int), cacheTtl (int seconds), fields (string)
     * @return list<array<string, mixed>>
     */
    public function loadFeed(array $params = []): array {
        $settings = $this->getSettings();
        $token    = $settings['access_token'] ?? null;
        if (!$token) return [];

        $limit    = (int)($params['limit']    ?? 12);
        $cacheTtl = (int)($params['cacheTtl'] ?? 3600);
        $fields   = $params['fields']         ?? 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username';

        $cacheFile = $this->cacheDir . '/feed.json';

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            return array_slice(json_decode(file_get_contents($cacheFile), true) ?? [], 0, $limit);
        }

        $url = 'https://graph.instagram.com/me/media?' . http_build_query([
            'fields'       => $fields,
            'limit'        => 50,
            'access_token' => $token,
        ]);

        $response = $this->apiGet($url);

        if (!is_array($response) || empty($response['data'])) {
            $this->logger->error('Feed fetch failed', ['url' => $url, 'response' => $response]);
            // Serve stale cache rather than empty response
            if (is_file($cacheFile))
                return array_slice(json_decode(file_get_contents($cacheFile), true) ?? [], 0, $limit);
            return [];
        }

        $media = $response['data'];
        file_put_contents($cacheFile, json_encode($media));
        return array_slice($media, 0, $limit);
    }

    // ─────────────────────── OAuth helpers ────────────────────────────────────

    /**
     * Returns the Instagram OAuth authorization URL.
     *
     * @param string $appId       Meta App ID
     * @param string $redirectUri Must match a URI registered in the Meta App settings
     * @return string
     */
    public function getAuthUrl(string $appId, string $redirectUri): string {
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $redirectUri,
            'scope'         => 'instagram_business_basic',
            'response_type' => 'code',
        ]);
    }

    /**
     * Exchanges an authorization code for a short-lived access token (~1h).
     *
     * @param string $code        Code from the OAuth callback
     * @param string $appId       Meta App ID
     * @param string $appSecret   Meta App Secret
     * @param string $redirectUri Same URI used during getAuthUrl()
     * @return array<string, mixed>  Contains 'access_token' and 'user_id' on success, empty on failure
     */
    public function exchangeCode(string $code, string $appId, string $appSecret, string $redirectUri): array {
        $response = $this->apiPost('https://api.instagram.com/oauth/access_token', [
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        if (!is_array($response) || empty($response['access_token'])) {
            $this->logger->error('Code exchange failed', ['response' => $response]);
            return [];
        }
        return $response;
    }

    /**
     * Exchanges a short-lived token for a long-lived token (60 days).
     *
     * @param string $shortToken  Short-lived token from exchangeCode()
     * @param string $appSecret   Meta App Secret
     * @return array<string, mixed>  Contains 'access_token' and 'expires_in' on success
     */
    public function getLongLivedToken(string $shortToken, string $appSecret): array {
        $url = 'https://graph.instagram.com/access_token?' . http_build_query([
            'grant_type'    => 'ig_exchange_token',
            'client_secret' => $appSecret,
            'access_token'  => $shortToken,
        ]);

        $response = $this->apiGet($url);

        if (!is_array($response) || empty($response['access_token'])) {
            $this->logger->error('Long-lived token exchange failed', ['response' => $response]);
            return [];
        }
        return $response;
    }

    /**
     * Refreshes a long-lived token, resetting its 60-day TTL.
     * Safe to call at any time while the token is still valid.
     *
     * @param string $token  Current long-lived access token
     * @return array<string, mixed>  Contains 'access_token' and 'expires_in' on success
     */
    public function refreshToken(string $token): array {
        $url = 'https://graph.instagram.com/refresh_access_token?' . http_build_query([
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $token,
        ]);

        $response = $this->apiGet($url);

        if (!is_array($response) || empty($response['access_token'])) {
            $this->logger->error('Token refresh failed', ['response' => $response]);
            return [];
        }
        return $response;
    }

    /**
     * Fetches basic account info for the token owner (verifies validity).
     *
     * @param string $token  Long-lived access token
     * @return array<string, mixed>  Contains 'id', 'name', 'username' on success
     */
    public function getMe(string $token): array {
        $url = 'https://graph.instagram.com/me?' . http_build_query([
            'fields'       => 'id,name,username',
            'access_token' => $token,
        ]);
        return $this->apiGet($url) ?? [];
    }

    // ─────────────────────── Cache ─────────────────────────────────────────────

    /**
     * @return string  Human-readable result message
     */
    public function clearCache(): string {
        $cacheFile = $this->cacheDir . '/feed.json';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
            return 'Instagram-Cache geleert.';
        }
        return 'Kein Instagram-Cache vorhanden.';
    }

    // ─────────────────────── Internal ─────────────────────────────────────────

    private function getSettings(): array {
        return (array)(ServiceLocator::get('Settings')->get('instagram') ?? []);
    }

    private function initCacheDir(): void {
        $dir = Helper::getNormDocRoot() . 'Cache/Instagram';
        if (!is_dir($dir))
            mkdir($dir, 0777, true);
        $this->cacheDir = $dir;
    }

    private function initLogging(): void {
        $logDir = Helper::getNormDocRoot() . (defined('VINOU_LOG_DIR') ? VINOU_LOG_DIR : 'logs/');
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $loglevel = defined('VINOU_DEBUG') && VINOU_DEBUG ? Logger::DEBUG : Logger::ERROR;
        $this->logger = new Logger('instagram');
        $this->logger->pushHandler(new RotatingFileHandler($logDir . 'instagram.log', 30, $loglevel));
    }

    /** @return array<string, mixed>|null */
    private function apiGet(string $url): ?array {
        $ctx  = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => "Accept: application/json\r\n",
            'timeout'         => 10,
            'ignore_errors'   => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? (json_decode($body, true) ?? null) : null;
    }

    /** @return array<string, mixed>|null */
    private function apiPost(string $url, array $data): ?array {
        $ctx  = stream_context_create(['http' => [
            'method'          => 'POST',
            'header'          => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content'         => http_build_query($data),
            'timeout'         => 10,
            'ignore_errors'   => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? (json_decode($body, true) ?? null) : null;
    }
}
