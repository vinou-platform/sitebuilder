<?php
namespace Vinou\SiteBuilder\Tools;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\FileHandler\Images;
use \Vinou\ApiConnector\FileHandler\Pdf;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\SiteBuilder\Processors\Shop;
use \Twig\Environment;
use \Twig\TwigFilter;
use \Gumlet\ImageResize;

/**
 * Registers all Vinou Twig filters on a Twig Environment instance.
 *
 * Receives the runtime data it needs (api, translation, regions, settings)
 * at construction time and injects them into filter closures. Called once
 * per request from Render::initTwig().
 */
class FilterRegistry {

    /** @var Api|null Vinou API instance for filters that fetch data. */
    private ?Api $api;

    /**
     * Localization data keyed by type (tastes, grapetypes, units, wineregions, countries).
     *
     * @var array<string, mixed>
     */
    private array $translation;

    /**
     * Flat wine-region map keyed by region ID.
     *
     * @var array<int|string, string>
     */
    private array $regions;

    /**
     * Full merged settings array (all keys including system.*).
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @param Api|null             $api          Vinou API instance, or null in Public mode.
     * @param array<string, mixed> $translation  Localization data from loadLocalization().
     * @param array<int|string, string> $regions  Flat region id → name map.
     * @param array<string, mixed> $settings     Full merged settings array.
     */
    public function __construct(?Api $api, array $translation, array $regions, array $settings) {
        $this->api         = $api;
        $this->translation = $translation;
        $this->regions     = $regions;
        $this->settings    = $settings;
    }

    /**
     * Registers all Vinou Twig filters on the given environment.
     *
     * @param Environment $twig  The Twig environment to add filters to.
     * @return void
     */
    public function registerAll(Environment $twig): void {
        $this->registerUtilityFilters($twig);
        $this->registerImageFilters($twig);
        $this->registerArrayFilters($twig);
        $this->registerShopFilters($twig);
        $this->registerHtmlFilters($twig);
        $this->registerApiFilters($twig);
    }

    /**
     * Registers general-purpose utility filters.
     *
     * Filters: cast_to_array, pdf, region, taste, gettype, http,
     *          filesize, bytes, brutto, netto, currency, cleanup, src,
     *          arraytocsv, sum, base64image, grapetypes, pageTitle, nl2p.
     *
     * @param Environment $twig
     * @return void
     */
    private function registerUtilityFilters(Environment $twig): void {
        $twig->addFilter(new TwigFilter('cast_to_array', function (mixed $stdClassObject): array {
            $response = [];
            foreach ($stdClassObject as $key => $value) {
                $response[$key] = (array)$value;
            }
            return $response;
        }));

        $twig->addFilter(new TwigFilter('pdf', function (string $pdfsrc, ?string $chstamp = null): mixed {
            return Pdf::storeApiPDF($pdfsrc, $chstamp);
        }));

        $twig->addFilter(new TwigFilter('region', function (mixed $region_id): mixed {
            if (!is_numeric($region_id))
                return false;
            return $this->regions[$region_id] ?? $region_id;
        }));

        $twig->addFilter(new TwigFilter('taste', function (mixed $taste_id): mixed {
            if (is_string($taste_id) && strlen($taste_id) > 0)
                return $this->translation['tastes'][$taste_id];
            return false;
        }));

        $twig->addFilter(new TwigFilter('gettype', function (mixed $var): string {
            return gettype($var);
        }));

        $twig->addFilter(new TwigFilter('http', function (string $src): string {
            if (strpos($src, '://'))
                return $src;
            return 'http://' . $src;
        }));

        $twig->addFilter(new TwigFilter('filesize', function (string $file): ?string {
            $file  = Helper::getNormDocRoot() . $file;
            $bytes = floatval(filesize($file));
            $arBytes = [
                ['UNIT' => 'TB', 'VALUE' => pow(1024, 4)],
                ['UNIT' => 'GB', 'VALUE' => pow(1024, 3)],
                ['UNIT' => 'MB', 'VALUE' => pow(1024, 2)],
                ['UNIT' => 'KB', 'VALUE' => 1024],
                ['UNIT' => 'B',  'VALUE' => 1],
            ];
            $result = null;
            foreach ($arBytes as $arItem) {
                if ($bytes >= $arItem['VALUE']) {
                    $result = $bytes / $arItem['VALUE'];
                    $result = str_replace('.', ',', strval(round($result, 2))) . ' ' . $arItem['UNIT'];
                    break;
                }
            }
            return $result;
        }));

        $twig->addFilter(new TwigFilter('bytes', function (int|float $bytes, int $precision = 2): string {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $bytes = max($bytes, 0);
            $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow   = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);
            return round($bytes, $precision) . ' ' . $units[$pow];
        }));

        $twig->addFilter(new TwigFilter('brutto', function (mixed $decimal): mixed {
            return $decimal;
        }));

        $twig->addFilter(new TwigFilter('netto', function (float|int $decimal): float {
            return ceil($decimal * 10000 / 119) / 100;
        }));

        $twig->addFilter(new TwigFilter('currency', function (float|int|null $decimal): string {
            return number_format(is_null($decimal) ? 0.00 : $decimal, 2, ',', '.');
        }));

        $twig->addFilter(new TwigFilter('cleanup', function (string $string): string {
            $string = str_starts_with($string, ' ') ? substr($string, 1) : $string;
            $string = str_replace(',', '', $string);
            $string = str_replace('@', '', $string);
            $string = str_replace('"', '', $string);
            return $string;
        }));

        $twig->addFilter(new TwigFilter('src', function (string $file): string|false {
            if (!is_file(Helper::getNormDocRoot() . '/' . $file))
                return false;
            $change_date = @filemtime(Helper::getNormDocRoot() . '/' . $file);
            if (!$change_date)
                $change_date = mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'));
            return $file . '?' . $change_date;
        }));

        $twig->addFilter(new TwigFilter('arraytocsv', function (mixed $array): string|false {
            return is_array($array) ? implode(',', $array) : false;
        }));

        $twig->addFilter(new TwigFilter('sum', function (array $arr): int|float {
            return array_reduce($arr, function ($carry, $item) { return $carry + $item; }, 0);
        }));

        $twig->addFilter(new TwigFilter('base64image', function (string $url): string {
            return Helper::imageToBase64($url);
        }));

        $twig->addFilter(new TwigFilter('grapetypes', function (array $array): array {
            $return = [];
            foreach ($array as $entry) {
                if (is_array($entry)) {
                    foreach ($entry as $id) {
                        $return[$id] = $this->translation['grapetypes'][$id]['name'];
                    }
                } else {
                    $return[$entry] = $this->translation['grapetypes'][$entry]['name'];
                }
            }
            asort($return);
            return $return;
        }));

        $twig->addFilter(new TwigFilter('pageTitle', function (array $object): string {
            $relevantFields = ['articlenumber', 'name', 'title'];
            $lowFields      = ['vintage'];
            $return         = '';
            foreach ($relevantFields as $field) {
                if (!empty($object[$field]))
                    $return .= $object[$field] . ' ';
            }
            $lowString = '';
            foreach ($lowFields as $field) {
                if (!empty($object[$field]))
                    $lowString .= $object[$field] . ' ';
            }
            if (strlen($lowString) > 0)
                $return .= '(' . trim($lowString) . ')';
            return trim($return);
        }));

        $twig->addFilter(new TwigFilter('nl2p', function (string $string): string {
            $arr = explode("\n", $string);
            $out = '';
            for ($i = 0; $i < count($arr); $i++) {
                if (strlen(trim($arr[$i])) > 0)
                    $out .= '<p>' . trim($arr[$i]) . '</p>';
            }
            return $out;
        }, ['pre_escape' => 'html', 'is_safe' => ['html']]));
    }

    /**
     * Registers image-related filters.
     *
     * Filters: image (with resize + WebP support).
     *
     * @param Environment $twig
     * @return void
     */
    private function registerImageFilters(Environment $twig): void {
        $twig->addFilter(new TwigFilter('image', function (
            string $imagesrc,
            ?string $chstamp = null,
            int|array|null $dimension = null
        ): array {
            $chstamp = $chstamp ?? 'now';

            // Proxy URL — returned whenever any cached version is missing
            $proxyParams = ['src' => $imagesrc, 'chstamp' => $chstamp];
            if (!is_null($dimension))
                $proxyParams['dim'] = is_array($dimension)
                    ? implode('x', $dimension)
                    : (string)$dimension;
            $proxyUrl = ['src' => '/image-proxy?' . http_build_query($proxyParams)];

            // Check whether the original is already cached locally (no download)
            $image     = Images::checkCache($imagesrc, $chstamp);
            $extension = strtolower(pathinfo($image['src'], PATHINFO_EXTENSION));

            if ($image['recreate'])
                return $proxyUrl;

            $localPath = $image['absolute'];

            // Resize version: check existence, do not generate here
            if ($extension !== 'svg' && !is_null($dimension)) {
                $prefix   = is_array($dimension)
                    ? $dimension[0] . 'x' . $dimension[1]
                    : (string)$dimension;
                $shrinked = dirname($localPath) . '/' . $prefix . '-' . basename($localPath);
                if (!is_file($shrinked))
                    return $proxyUrl;
                $localPath = $shrinked;
            }

            // WebP version: check existence, do not generate here
            if (ImageService::isWebPAllowed($this->settings)
                && ImageService::checkWebPEnvironment()
                && in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])
            ) {
                $webpPath = ImageService::replaceExtension($localPath, 'webp');
                if (!is_file($webpPath))
                    return $proxyUrl;
                $localPath = $webpPath;
            }

            // All versions present → direct local URL, no proxy
            $src = str_replace(Helper::getNormDocRoot(), '/', $localPath);
            return ['src' => $src, 'absolute' => $localPath, 'recreate' => false];
        }));
    }

    /**
     * Registers array manipulation filters.
     *
     * Filters: groupBy, sortBy, ksort, addProperty, subArray,
     *          withAttribute, withoutAttribute, price, wines, packages.
     *
     * @param Environment $twig
     * @return void
     */
    private function registerArrayFilters(Environment $twig): void {
        $twig->addFilter(new TwigFilter('groupBy', function (array $array, string $groupKey): array {
            $return = [];
            foreach ($array as $item) {
                if (isset($item[$groupKey])) {
                    if (!isset($return[$item[$groupKey]]))
                        $return[$item[$groupKey]] = [];
                    if (isset($item['id']))
                        $return[$item[$groupKey]][$item['id']] = $item;
                    else
                        $return[$item[$groupKey]][] = $item;
                }
            }
            return $return;
        }));

        $twig->addFilter(new TwigFilter('sortBy', function (array $array, string $property, string $direction = 'ASC'): array {
            usort($array, function ($a, $b) use ($property) {
                if (is_null($a[$property])) return 1;
                if (is_null($b[$property])) return 0;
                return $a[$property] <=> $b[$property];
            });
            return $direction === 'ASC' ? $array : array_reverse($array);
        }));

        $twig->addFilter(new TwigFilter('ksort', function (array $array): array {
            ksort($array);
            return $array;
        }));

        $twig->addFilter(new TwigFilter('addProperty', function (array $array, string $property, mixed $value): array {
            foreach ($array as &$entry) {
                $entry[$property] = $value;
            }
            return $array;
        }));

        $twig->addFilter(new TwigFilter('subArray', function (array $arr, string $index): array {
            return array_map(function ($item) use ($index) { return $item[$index]; }, $arr);
        }));

        $twig->addFilter(new TwigFilter('withAttribute', function (?array $arr, string $attr, mixed $value): ?array {
            if (is_null($arr) || empty($arr))
                return $arr;
            return array_filter($arr, function ($item) use ($attr, $value) {
                if (is_array($item[$attr]))
                    return isset($item[$attr][$value]);
                return $item[$attr] == $value;
            });
        }));

        $twig->addFilter(new TwigFilter('withoutAttribute', function (?array $arr, string $attr, mixed $value): ?array {
            if (is_null($arr) || empty($arr))
                return $arr;
            return array_filter($arr, function ($item) use ($attr, $value) {
                return $item[$attr] != $value;
            });
        }));

        $twig->addFilter(new TwigFilter('price', function (array $items): int|float {
            return array_reduce($items, function ($carry, $item) {
                return $carry + ($item['price'] * $item['quantity']);
            }, 0);
        }));

        $twig->addFilter(new TwigFilter('wines', function (array $items): array {
            return array_filter($items, function ($item) { return $item['type'] == 'wine'; });
        }));

        $twig->addFilter(new TwigFilter('packages', function (array $items): array {
            return array_filter($items, function ($item) { return $item['type'] == 'package'; });
        }));
    }

    /**
     * Registers shop-specific filters.
     *
     * Filters: quantityIsAllowed, basePrice.
     *
     * @param Environment $twig
     * @return void
     */
    private function registerShopFilters(Environment $twig): void {
        $shopSettings = $this->settings['settings'] ?? [];
        $twig->addFilter(new TwigFilter('quantityIsAllowed', function (int $quantity) use ($shopSettings): bool|string {
            return Shop::quantityIsAllowed($quantity, true, $shopSettings);
        }));

        $twig->addFilter(new TwigFilter('basePrice', function (float|int|null $price, string $unit): string {
            $factor = ['g' => 100, 'kg' => 1, 'ml' => 100, 'l' => 1];
            $price  = number_format(is_null($price) ? 0.00 : $price, 2, ',', '.');
            $suffix = isset($factor[$unit]) && $factor[$unit] > 1 ? $factor[$unit] . ' ' : '';
            $suffix .= $this->translation['units'][$unit];
            return '€ ' . $price . ' / ' . $suffix;
        }));
    }

    /**
     * Registers HTML-generating filters (marked is_safe html).
     *
     * Filters: link, language.
     *
     * @param Environment $twig
     * @return void
     */
    private function registerHtmlFilters(Environment $twig): void {
        $twig->addFilter(new TwigFilter('link', function (
            string $label,
            string $url,
            array|string|null $additionalParams = null,
            mixed $options = null
        ): string {
            $classSuffix = $_SERVER['REQUEST_URI'] == $url ? ' active' : false;
            $link = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';

            if (!is_array($additionalParams))
                $additionalParams = ['class' => is_string($additionalParams) ? $additionalParams : ''];

            foreach ($additionalParams as $attribute => $value) {
                if ($attribute === 'class' && $classSuffix)
                    $value .= $classSuffix;
                $link .= ' ' . htmlspecialchars($attribute, ENT_QUOTES, 'UTF-8')
                    . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }

            return $link . '>' . $label . '</a>';
        }, ['is_safe' => ['html']]));

        $twig->addFilter(new TwigFilter('language', function (
            string $value,
            array $translations,
            string $key,
            string $current
        ): string {
            $class = $current == $key ? 'active' : '';
            if (isset($translations[$key]))
                return '<a href="' . $translations[$key] . '" class="' . $class . '">' . $value . '</a>';
            return '<a class="disabled ' . $class . '">' . $value . '</a>';
        }, ['pre_escape' => 'html', 'is_safe' => ['html']]));
    }

    /**
     * Registers filters that call the Vinou API.
     *
     * Filters: getBundle, getWinery.
     *
     * @param Environment $twig
     * @return void
     */
    private function registerApiFilters(Environment $twig): void {
        $twig->addFilter(new TwigFilter('getBundle', function (int $id): mixed {
            return $this->api->getBundle($id);
        }));

        $twig->addFilter(new TwigFilter('getWinery', function (int $id): mixed {
            return $this->api->getWinery($id);
        }));
    }
}
