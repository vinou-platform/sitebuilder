<?php
namespace Vinou\SiteBuilder\Tools;

/**
 * Builds schema.org structured data (JSON-LD) for shop pages.
 *
 * Pure data builder without Twig or request dependencies: callers pass in
 * everything that depends on the request (absolute URLs, client state). The
 * Twig function `structuredData()` in FilterRegistry wires it to templates;
 * the theme decides where the output is placed.
 */
class StructuredData {

    /**
     * Maximum length of descriptions in structured data.
     */
    private const DESCRIPTION_LENGTH = 5000;

    /**
     * Product (+ Offer) for a wine, bundle or product detail page.
     *
     * @param array<string, mixed> $article  Wine, bundle or product record from the API.
     * @param array{
     *     url: string,
     *     brand?: string|null,
     *     seller?: string|null,
     *     image?: string|null,
     *     useClientPrice?: bool,
     *     currency?: string
     * } $context  Request-dependent values (absolute URLs, client state).
     * @return array<string, mixed>
     */
    public static function product(array $article, array $context): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => (string)($article['name'] ?? ''),
            'url'      => $context['url'],
        ];

        $description = self::plainText($article['meta_description'] ?? '') ?: self::plainText($article['description'] ?? '');
        if ($description !== '')
            $data['description'] = $description;

        if (!empty($context['image']))
            $data['image'] = $context['image'];

        if (!empty($article['articlenumber']))
            $data['sku'] = (string)$article['articlenumber'];

        if (!empty($article['gtin']))
            $data['gtin'] = (string)$article['gtin'];

        if (!empty($context['brand']))
            $data['brand'] = ['@type' => 'Brand', 'name' => $context['brand']];

        $category = self::firstCategoryName($article);
        if ($category !== null)
            $data['category'] = $category;

        $price = self::price($article, !empty($context['useClientPrice']));
        if ($price > 0) {
            $offer = [
                '@type'         => 'Offer',
                'url'           => $context['url'],
                'price'         => number_format($price, 2, '.', ''),
                'priceCurrency' => $context['currency'] ?? 'EUR',
                'availability'  => !empty($article['nostock'])
                    ? 'https://schema.org/OutOfStock'
                    : 'https://schema.org/InStock',
            ];
            if (!empty($context['seller']))
                $offer['seller'] = ['@type' => 'Organization', 'name' => $context['seller']];
            $data['offers'] = $offer;
        }

        return $data;
    }

    /**
     * Winery and website, intended for every page.
     *
     * @param string      $name     Company name of the shop owner.
     * @param string      $baseUrl  Absolute base URL without trailing slash (e.g. https://shop.example.com).
     * @param string|null $logo     Absolute logo URL, optional.
     * @param string      $language Content language (BCP 47).
     * @return array<string, mixed>
     */
    public static function site(string $name, string $baseUrl, ?string $logo = null, string $language = 'de-DE'): array {
        $baseUrl      = rtrim($baseUrl, '/');
        $organization = [
            '@type' => 'Winery',
            '@id'   => $baseUrl . '/#organization',
            'name'  => $name,
            'url'   => $baseUrl . '/',
        ];
        if (!empty($logo))
            $organization['logo'] = $logo;

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [
                $organization,
                [
                    '@type'      => 'WebSite',
                    '@id'        => $baseUrl . '/#website',
                    'name'       => $name,
                    'url'        => $baseUrl . '/',
                    'publisher'  => ['@id' => $baseUrl . '/#organization'],
                    'inLanguage' => $language,
                ],
            ],
        ];
    }

    /**
     * Renders data as a JSON-LD script tag.
     *
     * JSON_HEX_TAG keeps "</script>" in API texts from closing the tag early.
     *
     * @param array<string, mixed> $data
     * @return string
     */
    public static function toScript(array $data): string {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false)
            return '';
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Price shown to the visitor: the personal client price if present, else the gross price.
     *
     * @param array<string, mixed> $article
     * @param bool                 $useClientPrice  True when a client is logged in.
     * @return float
     */
    public static function price(array $article, bool $useClientPrice): float {
        if ($useClientPrice && !empty($article['prices']) && is_array($article['prices'])) {
            $first = reset($article['prices']);
            if (is_array($first) && isset($first['gross']) && is_numeric($first['gross']))
                return (float)$first['gross'];
        }
        return is_numeric($article['gross'] ?? null) ? (float)$article['gross'] : 0.0;
    }

    /**
     * Strips HTML and collapses whitespace.
     *
     * @param mixed $text
     * @return string
     */
    public static function plainText(mixed $text): string {
        if (!is_string($text) || $text === '')
            return '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, self::DESCRIPTION_LENGTH);
    }

    /**
     * @param array<string, mixed> $article
     * @return string|null
     */
    private static function firstCategoryName(array $article): ?string {
        if (empty($article['categories']) || !is_array($article['categories']))
            return null;
        $first = reset($article['categories']);
        return is_array($first) && !empty($first['name']) ? (string)$first['name'] : null;
    }
}
