<?php
namespace Vinou\SiteBuilder\Tools;

/**
 * Country lookup for IP addresses without external libraries or licences.
 *
 * Data source: DB-IP "IP to Country Lite" (CC BY 4.0, monthly, free) –
 * https://db-ip.com/db/download/ip-to-country-lite. Attribution required:
 * "IP Geolocation by DB-IP" (shown on the geo blocking page).
 *
 * build() converts the CSV (start_ip,end_ip,country) into two compact,
 * sorted binary files – one per IP family – with fixed-size records:
 *   IPv4: 4 bytes start | 4 bytes end | 2 bytes country  (10 bytes)
 *   IPv6: 16 bytes start | 16 bytes end | 2 bytes country (34 bytes)
 * Adjacent ranges of the same country are merged. lookup() does a binary
 * search with fseek, so only ~20 records are read per request.
 */
class GeoIp {

    private const RECORD = [4 => 10, 16 => 34];

    public function __construct(private string $dir) {
        $this->dir = rtrim($dir, '/') . '/';
    }

    /**
     * Returns the ISO 3166-1 alpha-2 code (upper case) or null if unknown,
     * the IP is invalid or no database is available.
     */
    public function lookup(string $ip): ?string {
        $packed = @inet_pton($ip);
        if ($packed === false)
            return null;

        $len  = strlen($packed);
        $file = $this->dir . ($len === 4 ? 'geoip-v4.bin' : 'geoip-v6.bin');
        if (!is_file($file) || !($fh = @fopen($file, 'rb')))
            return null;

        $size  = self::RECORD[$len];
        $count = intdiv(filesize($file), $size);
        $lo = 0;
        $hi = $count - 1;
        $result = null;

        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            fseek($fh, $mid * $size);
            $rec   = fread($fh, $size);
            $start = substr($rec, 0, $len);
            $end   = substr($rec, $len, $len);

            if (strcmp($packed, $start) < 0) {
                $hi = $mid - 1;
            } elseif (strcmp($packed, $end) > 0) {
                $lo = $mid + 1;
            } else {
                $code   = substr($rec, 2 * $len, 2);
                $result = ($code === 'ZZ') ? null : $code;
                break;
            }
        }

        fclose($fh);
        return $result;
    }

    /**
     * Builds the binary files from a DB-IP country CSV (plain or .gz).
     * Writes to temp files first and swaps them in, so lookups never see a
     * half-written database.
     *
     * @return array{v4:int, v6:int}  Number of (merged) ranges per family.
     */
    public function build(string $csvFile): array {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true))
            throw new \RuntimeException('Cannot create ' . $this->dir);

        $in = str_ends_with($csvFile, '.gz') ? gzopen($csvFile, 'rb') : fopen($csvFile, 'rb');
        if (!$in)
            throw new \RuntimeException('Cannot read ' . $csvFile);

        $out  = [];
        $last = [];
        $counts = [4 => 0, 16 => 0];
        foreach ([4, 16] as $len) {
            $tmp = $this->dir . ($len === 4 ? 'geoip-v4.bin' : 'geoip-v6.bin') . '.tmp';
            $out[$len] = ['tmp' => $tmp, 'fh' => fopen($tmp, 'wb')];
            $last[$len] = null;
        }

        $read = fn() => str_ends_with($csvFile, '.gz') ? gzgets($in) : fgets($in);
        while (($line = $read()) !== false) {
            $parts = str_getcsv(trim($line));
            if (count($parts) < 3)
                continue;

            $start = @inet_pton($parts[0]);
            $end   = @inet_pton($parts[1]);
            $cc    = strtoupper(substr($parts[2], 0, 2));
            if ($start === false || $end === false || strlen($start) !== strlen($end) || strlen($cc) !== 2)
                continue;

            $len  = strlen($start);
            $prev = &$last[$len];

            // merge with the previous range if contiguous and same country
            if ($prev !== null && $prev['cc'] === $cc && $this->increment($prev['end']) === $start) {
                $prev['end'] = $end;
            } else {
                if ($prev !== null) {
                    fwrite($out[$len]['fh'], $prev['start'] . $prev['end'] . $prev['cc']);
                    $counts[$len]++;
                }
                $prev = ['start' => $start, 'end' => $end, 'cc' => $cc];
            }
            unset($prev);
        }

        str_ends_with($csvFile, '.gz') ? gzclose($in) : fclose($in);

        foreach ([4, 16] as $len) {
            if ($last[$len] !== null) {
                fwrite($out[$len]['fh'], $last[$len]['start'] . $last[$len]['end'] . $last[$len]['cc']);
                $counts[$len]++;
            }
            fclose($out[$len]['fh']);
            rename($out[$len]['tmp'], substr($out[$len]['tmp'], 0, -4));
        }

        return ['v4' => $counts[4], 'v6' => $counts[16]];
    }

    /**
     * Returns the visitor's country if it is listed in settings.geoBlocking.countries,
     * otherwise null. Country source: host/CDN header (countryHeader), then the
     * local database in config/geoip/. Fail-open: unknown country = not blocked.
     *
     * @param array<string, mixed>|null $config  settings.geoBlocking
     */
    public static function blockedCountry(?array $config): ?string {
        if (!is_array($config) || empty($config['countries']))
            return null;

        $country = null;
        $header  = $config['countryHeader'] ?? null;
        if ($header && !empty($_SERVER[$header]) && preg_match('/^[A-Za-z]{2}$/', $_SERVER[$header]))
            $country = strtoupper($_SERVER[$header]);
        elseif (!empty($_SERVER['REMOTE_ADDR']))
            $country = (new self(self::defaultDir()))->lookup($_SERVER['REMOTE_ADDR']);

        $blocked = array_map('strtoupper', (array)$config['countries']);
        return ($country !== null && in_array($country, $blocked, true)) ? $country : null;
    }

    /** True if $path equals or lies below one of the prefixes; '*' matches all. */
    public static function pathMatches(string $path, array $prefixes): bool {
        $path = trim($path, '/');
        foreach ($prefixes as $prefix) {
            $prefix = trim((string)$prefix, '/');
            if ($prefix === '*' || $path === $prefix || ($prefix !== '' && str_starts_with($path, $prefix . '/')))
                return true;
        }
        return false;
    }

    /** config/geoip/ of the project (outside the webroot). */
    public static function defaultDir(): string {
        return \Vinou\ApiConnector\Tools\Helper::getNormDocRoot()
            . (defined('VINOU_CONFIG_DIR') ? VINOU_CONFIG_DIR : '../config/') . 'geoip/';
    }

    /** Adds 1 to a packed big-endian address (wraps at the top). */
    private function increment(string $packed): string {
        for ($i = strlen($packed) - 1; $i >= 0; $i--) {
            $byte = ord($packed[$i]);
            if ($byte < 255) {
                $packed[$i] = chr($byte + 1);
                return $packed;
            }
            $packed[$i] = chr(0);
        }
        return $packed;
    }
}
