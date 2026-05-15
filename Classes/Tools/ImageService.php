<?php
namespace Vinou\SiteBuilder\Tools;

use \Vinou\ApiConnector\FileHandler\Images;
use \Gumlet\ImageResize;

/**
 * Static utility methods for image processing and WebP conversion.
 *
 * Extracted from Render to separate image concerns from page rendering.
 * All methods are stateless and safe to call without instantiation.
 */
class ImageService {

    /**
     * Returns true when WebP rendering is enabled in the merged settings.
     *
     * @param array<string, mixed> $settings  Full merged settings array.
     * @return bool
     */
    public static function isWebPAllowed(array $settings): bool {
        return isset($settings['system']['performance']['webpRendering'])
            && $settings['system']['performance']['webpRendering'] === true;
    }

    /**
     * Returns true when the GD extension supports WebP encoding.
     *
     * @return bool
     */
    public static function checkWebPEnvironment(): bool {
        if (!extension_loaded('gd'))
            return false;
        $gdInfo = gd_info();
        return function_exists('imagewebp') && !empty($gdInfo['WebP Support']);
    }

    /**
     * Converts a source image to WebP format and saves it to $target.
     *
     * PNG transparency is preserved via alpha blending flags. GIF transparency
     * may be lost due to GD limitations. Returns $source unchanged on any
     * conversion failure so callers always receive a valid path.
     *
     * @param string $source   Absolute path to the source image (jpg, jpeg, png, gif).
     * @param string $target   Absolute output path for the .webp file.
     * @param int    $quality  WebP quality 0–100 (default 100).
     * @return string  Path to the converted WebP file, or $source on failure.
     */
    public static function convertToWebP(string $source, string $target, int $quality = 100): string {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'jpeg':
            case 'jpg':
                $image = imagecreatefromjpeg($source);
                if (!$image) return $source;
                break;
            case 'png':
                $image = imagecreatefrompng($source);
                if (!$image) return $source;
                imagepalettetotruecolor($image);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                break;
            case 'gif':
                $image = imagecreatefromgif($source);
                if (!$image) return $source;
                break;
            default:
                return $source;
        }

        if (!imagewebp($image, $target, $quality))
            return $source;

        imagedestroy($image);
        return $target;
    }

    /**
     * Replaces the file extension of a path.
     *
     * @param string $filename   Original file path.
     * @param string $extension  New extension without leading dot.
     * @return string
     */
    public static function replaceExtension(string $filename, string $extension): string {
        $info = pathinfo($filename);
        return $info['dirname'] . '/' . $info['filename'] . '.' . $extension;
    }

    /**
     * On-demand image proxy handler.
     *
     * Downloads the image from the Vinou API if not yet cached locally, applies
     * optional resize and WebP conversion, then streams the result to the browser
     * with appropriate caching headers. Terminates the request via exit().
     *
     * Only requests targeting api.vinou.de are allowed (SSRF guard).
     *
     * @param string               $src        Original image path or URL.
     * @param string               $chstamp    Change timestamp for cache invalidation.
     * @param int|int[]|null       $dimension  Width (int) or [width, height] for resize; null = no resize.
     * @param array<string, mixed> $settings   Full merged settings array (used for WebP check).
     * @return void
     */
    public static function serveProxy(
        string $src,
        string $chstamp,
        int|array|null $dimension,
        array $settings
    ): void {
        // SSRF guard: only allow downloads from api.vinou.de
        $resolvedUrl = filter_var($src, FILTER_VALIDATE_URL) !== false
            ? $src
            : 'https://api.vinou.de' . $src;
        if (parse_url($resolvedUrl, PHP_URL_HOST) !== 'api.vinou.de') {
            http_response_code(403);
            exit;
        }

        $image     = Images::storeApiImage($src, $chstamp);
        $extension = strtolower(pathinfo($image['src'], PATHINFO_EXTENSION));

        // SVGs are not stored locally by storeApiImage — redirect to origin
        if ($extension === 'svg' || !is_file($image['absolute'] ?? '')) {
            header('Location: ' . $resolvedUrl);
            exit;
        }

        // Optional local resize
        if (!is_null($dimension)) {
            $prefix   = is_array($dimension)
                ? $dimension[0] . 'x' . $dimension[1]
                : (string)$dimension;
            $shrinked = dirname($image['absolute']) . '/' . $prefix . '-' . basename($image['absolute']);
            if (!is_file($shrinked) || ($image['recreate'] ?? false)) {
                $resize = new ImageResize($image['absolute']);
                is_int($dimension)
                    ? $resize->resizeToWidth($dimension)
                    : $resize->resizeToBestFit($dimension[0], $dimension[1]);
                $resize->save($shrinked);
            }
            $image['absolute'] = $shrinked;
        }

        // WebP conversion
        if (self::isWebPAllowed($settings)
            && self::checkWebPEnvironment()
            && in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])
        ) {
            $webpPath  = self::replaceExtension($image['absolute'], 'webp');
            $converted = self::convertToWebP($image['absolute'], $webpPath);
            if ($converted !== $image['absolute'])
                $image['absolute'] = $converted;
        }

        if (!is_file($image['absolute'])) {
            http_response_code(404);
            exit;
        }

        $finalExt = strtolower(pathinfo($image['absolute'], PATHINFO_EXTENSION));
        $mimeMap  = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];

        header_remove('Cache-Control');
        header_remove('Pragma');
        header('Content-Type: ' . ($mimeMap[$finalExt] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400, immutable');
        header('Content-Length: ' . filesize($image['absolute']));
        readfile($image['absolute']);
        exit;
    }
}
