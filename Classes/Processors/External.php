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
     * @param array<string, mixed> $params  Must contain key 'url' with the target URL.
     * @return string|array<string, mixed>|false  Raw response body on HTTP 200,
     *                                            error array on HTTP 401 or other errors,
     *                                            false if no URL was provided.
     */
    public function loadURL(array $params): string|array|false {
        if (!isset($params['url']))
            return false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $params['url']);
        $result = curl_exec($ch);
        $requestinfo = curl_getinfo($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch ($httpCode) {
            case 200:
                curl_close($ch);
                return $result;
            case 401:
                return [
                    'error' => 'unauthorized',
                    'info' => $requestinfo,
                    'response' => $result
                ];
            default:
                return [
                    'error' => 'an error occured',
                    'info' => $requestinfo,
                    'response' => $result
                ];
        }
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
