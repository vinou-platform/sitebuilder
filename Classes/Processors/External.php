<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;

/**
 * Processor for loading external resources in dataProcessing steps.
 *
 * Fetches remote URLs via cURL or reads local files from the webroot.
 * Registered under the key 'external' by default in Site::loadDefaultProcessors().
 */
class External implements ProcessorInterface {

    /** @var array<string, mixed> Shared data storage. */
    public array $data = [];

    public function __construct() {}

    /**
     * Fetches content from an external URL via cURL.
     *
     * Optional params (all backward compatible):
     *  - timeout         total request timeout in seconds (default 10)
     *  - connectTimeout  connect timeout in seconds (default 5)
     *  - cacheTime       seconds a successful response is served from
     *                    Cache/External/ without a new request (default 0 = off)
     *  - minLength       responses shorter than this count as failure, e.g. an
     *                    error message delivered with HTTP 200 (default 0 = off)
     *
     * With cacheTime > 0 a failed request falls back to the last successful
     * (stale) response, so a slow or unreachable remote never blocks the page
     * longer than the timeout and never replaces good content with an error.
     *
     * @param array<string, mixed> $params  Must contain key 'url' with the target URL.
     * @return string|array<string, mixed>|false  Raw response body on success,
     *                                            error array on HTTP 401 or other errors,
     *                                            false if no URL was provided.
     */
    public function loadURL(array $params): string|array|false {
        if (!isset($params['url']))
            return false;

        $cacheTime = (int)($params['cacheTime'] ?? 0);
        $minLength = (int)($params['minLength'] ?? 0);
        $cacheFile = $cacheTime > 0 ? $this->getCacheFile($params['url']) : null;

        if ($cacheFile && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime)
            return file_get_contents($cacheFile);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $params['url']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)($params['connectTimeout'] ?? 5));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)($params['timeout'] ?? 10));
        $result = curl_exec($ch);
        $requestinfo = curl_getinfo($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($result) && strlen($result) >= $minLength) {
            if ($cacheFile)
                $this->writeCache($cacheFile, $result);
            return $result;
        }

        // Stale cache beats an error page
        if ($cacheFile && is_file($cacheFile))
            return file_get_contents($cacheFile);

        return [
            'error' => $httpCode === 401 ? 'unauthorized' : 'an error occured',
            'info' => $requestinfo,
            'response' => $result
        ];
    }

    /**
     * Returns the cache file path for a URL inside Cache/External/.
     *
     * @param string $url
     * @return string|null  Null if the cache directory cannot be created.
     */
    private function getCacheFile(string $url): ?string {
        $dir = Helper::getNormDocRoot() . 'Cache/External';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true))
            return null;

        return $dir . '/' . md5($url) . '.cache';
    }

    /**
     * Writes the cache atomically, so a parallel request never reads a half file.
     *
     * @param string $file
     * @param string $content
     */
    private function writeCache(string $file, string $content): void {
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $content) !== false)
            @rename($tmp, $file);
    }

    /**
     * Reads and parses a local file from the webroot.
     *
     * @param string $file  Absolute path or path relative to the webroot.
     * @param string $type  File format to parse; currently supports 'json'.
     * @return array<mixed>|false|string  Parsed content, false for unsupported types,
     *                                    or 'file not found' string if the file is missing.
     */
    public function loadFile(string $file, string $type): array|false|string {
        if (substr($file, 0, 1) !== '/')
            $file = Helper::getNormDocRoot() . $file;

        if (!is_file($file))
            return 'file not found';

        switch (strtolower($type)) {
            case 'json':
                return json_decode(file_get_contents($file), true);
            default:
                return false;
        }
    }

    /**
     * Convenience wrapper: reads a local JSON file and returns its decoded content.
     *
     * @param array<string, mixed> $params  Must contain key 'file' with the file path.
     * @return array<mixed>|false  Decoded JSON array, or false if no file key was given.
     */
    public function loadJSONFile(array $params): array|false {
        if (!isset($params['file']))
            return false;

        return $this->loadFile($params['file'], 'json');
    }
}
