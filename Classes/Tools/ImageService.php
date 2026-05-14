<?php
namespace Vinou\SiteBuilder\Tools;

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
}
