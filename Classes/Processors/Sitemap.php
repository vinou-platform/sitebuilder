<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\SiteBuilder\Router\DynamicRoutes;
use \Thepixeldeveloper\Sitemap\Urlset;
use \Thepixeldeveloper\Sitemap\Url;
use \Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;
use \Thepixeldeveloper\Sitemap\Extensions\Image;

/**
 * Processor for generating sitemaps from the active route configuration.
 *
 * Supports both a structured PHP array representation (renderSitemap) and
 * an XML sitemap response (renderSitemapXML) compliant with the sitemap
 * protocol. Dynamic routes with URL wildcards are expanded by fetching the
 * full item list from the Vinou API.
 *
 * Registered under the key 'sitemap' by default in Site::loadDefaultProcessors().
 */
class Sitemap {

    /** @var array<string, mixed> Shared data storage. */
    public array $data = [];

    /** @var array<string, mixed> Processed route listing snapshot. */
    public array $routes = [];

    /** @var DynamicRoutes Route configuration object from the active router. */
    public DynamicRoutes $routeConfig;

    /** @var Api Vinou API instance for fetching dynamic sitemap entries. */
    public Api $api;

    /**
     * @param DynamicRoutes $routeConfig  Reference to the active router configuration.
     * @param Api           $api          Reference to the active Vinou API instance.
     * @throws \Exception If either dependency is null.
     */
    public function __construct(DynamicRoutes &$routeConfig, Api &$api) {
        $this->api         = $api;
        $this->routeConfig = $routeConfig;
    }

    /**
     * Converts a slash-delimited URL string into a nested associative map.
     *
     * Used internally to build a tree-shaped sitemap array from flat URL paths.
     *
     * @param string               $array      URL segment string (e.g. 'weine/rotwein').
     * @param string               $delimiter  Path separator, typically '/'.
     * @param array<string, mixed> $object     Leaf data to place at the deepest level.
     * @return array<string, mixed>  Nested path map with 'subpages' keys.
     */
    public function arrayToMap(string $array, string $delimiter, array $object): array {
        $split = explode($delimiter, $array, 2);
        if (isset($split[1]))
            return [
                $split[0] => [
                    'subpages' => $this->arrayToMap($split[1], $delimiter, $object)
                ]
            ];
        else
            return [$split[0] => $object];
    }

    /**
     * Builds a structured PHP array of all sitemap-eligible routes.
     *
     * Routes with sitemap set to true generate a single entry. Routes with
     * a sitemap configuration array and a URL wildcard are expanded by
     * calling the specified API function to enumerate all items.
     *
     * @return array<string, mixed>  Nested tree of sitemap entries with 'title' and 'url' keys.
     */
    public function renderSitemap(): array {
        $entries = [];

        foreach ($this->routeConfig->configuration as $url => $routeConfig) {
            if (!isset($routeConfig['sitemap']) || $url === '/')
                continue;

            $config = $routeConfig['sitemap'];

            preg_match_all('/{(.+?)}/', $url, $matches);
            if (count($matches[1]) > 0) {
                if (!isset($config['function']))
                    continue;

                $function   = $config['function'];
                $dataKey    = $config['dataKey'] ?? 'data';
                $postData   = $config['params'] ?? [];
                $result     = $this->api->{$function}($postData);
                $data       = $result[$dataKey] ?? $result;
                $titleField = $config['titleField'] ?? 'name';

                $data = $this->fetchPaginatedResults($function, $postData, $result, $data, $dataKey);

                if (!is_array($data) || empty($data))
                    continue;

                foreach ($data as $entry) {
                    foreach ($matches[1] as $fieldName) {
                        if (!isset($entry[$fieldName]))
                            continue;

                        $suburl   = str_replace('{' . $fieldName . '}', $entry[$fieldName], $url);
                        $dataPage = [
                            'title' => $entry[$titleField] ?? false,
                            'url'   => '/' . $suburl
                        ];
                        $entries = array_merge_recursive($entries, $this->arrayToMap($suburl, '/', $dataPage));
                    }
                }
            } else {
                $page    = ['title' => $routeConfig['pageTitle'], 'url' => '/' . $url];
                $entries = array_merge_recursive($entries, $this->arrayToMap($url, '/', $page));
            }
        }

        return $entries;
    }

    /**
     * Renders and outputs a sitemap.xml response directly to the client.
     *
     * Sets the Content-Type header to text/xml, builds a Urlset from all
     * sitemap-eligible routes, and echoes the XML output. Dynamic routes
     * with URL wildcards are expanded via the Vinou API. Product images are
     * included as sitemap image extensions when available.
     *
     * @param array<string, mixed> $routes  The full route configuration array,
     *                                      typically from DynamicRoutes::$configuration.
     */
    public function renderSitemapXML(array $routes): void {
        header('Content-type: text/xml');

        $date   = new \DateTime();
        $urlset = new Urlset();

        foreach ($routes as $url => $routeConfig) {
            if (!isset($routeConfig['sitemap']))
                continue;

            $url    = ($url === '/') ? '/' : '/' . $url;
            $url    = Helper::getCurrentHost() . $url;
            $config = $routeConfig['sitemap'];

            preg_match_all('/{(.+?)}/', $url, $matches);

            if (count($matches[1]) > 0) {
                if (!isset($config['function']))
                    continue;

                $function = $config['function'];
                $dataKey  = $config['dataKey'] ?? 'data';
                $postData = $config['params'] ?? [];
                $result   = $this->api->{$function}($postData);
                $data     = $result[$dataKey] ?? $result;

                $data = $this->fetchPaginatedResults($function, $postData, $result, $data, $dataKey);

                if (!is_iterable($data))
                    continue;

                foreach ($data as $entry) {
                    $createUrl = true;
                    $entryUrl  = $url;

                    foreach ($matches[1] as $fieldName) {
                        if (isset($entry[$fieldName]))
                            $entryUrl = str_replace('{' . $fieldName . '}', $entry[$fieldName], $entryUrl);
                        else
                            $createUrl = false;
                    }

                    if (!$createUrl)
                        continue;

                    $entryDate  = isset($entry['chstamp']) ? new \DateTime($entry['chstamp']) : $date;
                    $siteMapUrl = new Url($entryUrl);
                    $siteMapUrl->setLastMod($entryDate);
                    $siteMapUrl->setChangeFreq('weekly');
                    $siteMapUrl->setPriority('0.8');

                    if (!empty($entry['image'])) {
                        $image = new Image('https://api.vinou.de' . $entry['image']);
                        $siteMapUrl->addExtension($image);
                    }

                    $urlset->add($siteMapUrl);
                }
            } else {
                $priority   = ($url === Helper::getCurrentHost() . '/') ? '1.0' : '0.6';
                $changeFreq = ($url === Helper::getCurrentHost() . '/') ? 'daily' : 'monthly';
                $siteMapUrl = new Url($url);
                $siteMapUrl->setLastMod($date);
                $siteMapUrl->setChangeFreq($changeFreq);
                $siteMapUrl->setPriority($priority);
                $urlset->add($siteMapUrl);
            }
        }

        $driver = new XmlWriterDriver();
        $urlset->accept($driver);
        echo $driver->output();
    }

    /**
     * Fetches all pages of a paginated API result and merges them into one array.
     *
     * @param string               $function  API function name to call for subsequent pages.
     * @param array<string, mixed> $postData  Base parameters passed to the API function.
     * @param array<string, mixed> $result    First-page API result, checked for pagination.
     * @param array<mixed>         $data      First-page data array to extend.
     * @param string               $dataKey   Key to extract data from subsequent page results.
     * @return array<mixed>  Complete merged data array across all pages.
     */
    private function fetchPaginatedResults(
        string $function,
        array  $postData,
        array  $result,
        array  $data,
        string $dataKey
    ): array {
        if (!isset($result['pagination']))
            return $data;

        $start = $result['pagination']['current'] + 1;
        $end   = $result['pagination']['total'];

        for ($i = $start; $i <= $end; $i++) {
            $postData['page'] = $i;
            $pageResult       = $this->api->{$function}($postData);
            $page             = $pageResult[$dataKey] ?? $pageResult;
            $data             = array_merge($data, $page);
        }

        return $data;
    }
}
