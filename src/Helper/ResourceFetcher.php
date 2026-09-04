<?php
namespace OSC\Helper;

use Ahc\Cli\Exception\InvalidArgumentException;
use Exception;

/**
 * Helper class to fetch content from files or URLs
 *
 * Usage examples:
 *
 * ```php
 * // Fetch from file
 * $content = ResourceFetcher::fetch('/path/to/file.txt');
 *
 * // Fetch from URL
 * $content = ResourceFetcher::fetch('https://example.org/data.json');
 *
 * // Validate without fetching
 * ResourceFetcher::validate($source);
 *
 * // Check type
 * if (ResourceFetcher::isFile($source)) {
 *     // Handle as file
 * } else {
 *     // Handle as URL
 * }
 *
 * // Detect type
 * $type = ResourceFetcher::detectType($source); // Returns 'file' or 'url'
 * ```
 */
class ResourceFetcher
{
    public const FILE = "file";
    public const URL = "url";

    /**
     * Fetch content from a file or URL
     *
     * @param string   $source  Path to file or URL
     * @param string[] $headers Request headers as "Name: value" strings, applied to URL sources only
     * @return string The content
     * @throws InvalidArgumentException If the source is invalid or inaccessible
     * @throws Exception If fetching fails
     */
    public static function fetch(string $source, array $headers = []): string
    {
        $type = self::detectType($source);

        if ($type === self::FILE) {
            return self::fetchFromFile($source);
        } else {
            return self::fetchFromUrl($source, $headers);
        }
    }

    /** Fetch content from a file or URL and decode json */
    public static function fetchJson(string $source): mixed
    {
        $content = self::fetch($source);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON from source: {$source}. Error: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Detect if source is a file or URL
     *
     * @param string $source
     * @return string 'file' or 'url'
     */
    public static function detectType(string $source): string
    {
        // Check if it's a URL
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return self::URL;
        }

        // Check if it starts with http:// or https://
        if (preg_match('/^https?:\/\//i', $source)) {
            return 'url';
        }

        // Otherwise treat as file
        return self::FILE;
    }

    /**
     * Fetch content from a file
     *
     * @param string $filePath
     * @return string
     * @throws InvalidArgumentException If file doesn't exist or is not readable
     * @throws Exception If reading fails
     */
    protected static function fetchFromFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        $content = @file_get_contents($filePath);

        if ($content === false) {
            throw new Exception("Failed to read file: {$filePath}");
        }

        return $content;
    }

    /**
     * Fetch content from a URL
     *
     * @param string   $url
     * @param string[] $headers Request headers as "Name: value" strings
     * @return string
     * @throws InvalidArgumentException If URL is invalid
     * @throws Exception If fetching fails
     */
    protected static function fetchFromUrl(string $url, array $headers = []): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        // cURL is the preferred transport: it reports the HTTP status code explicitly
        if (function_exists('curl_init')) {
            return self::fetchWithCurl($url, $headers);
        }

        if (!ini_get('allow_url_fopen')) {
            throw new Exception("Cannot fetch URL: cURL is not available and allow_url_fopen is disabled");
        }

        return self::fetchWithStreamWrapper($url, $headers);
    }

    /**
     * Fetch content using cURL
     *
     * @param string   $url
     * @param string[] $headers Request headers as "Name: value" strings
     * @return string
     * @throws Exception If cURL request fails
     */
    protected static function fetchWithCurl(string $url, array $headers = []): string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Omeka-S-CLI/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $content = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($content === false) {
            throw new Exception("cURL error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new Exception("HTTP error {$httpCode} when fetching URL: {$url}");
        }

        return $content;
    }

    /**
     * Fetch content using the http stream wrapper
     *
     * Fallback for hosts without ext-curl. The wrapper does not expose the HTTP status
     * code as a return value, so failures are reported without it.
     *
     * @param string   $url
     * @param string[] $headers Request headers as "Name: value" strings
     * @return string
     * @throws Exception If the request fails
     */
    protected static function fetchWithStreamWrapper(string $url, array $headers = []): string
    {
        $http = [
            'timeout' => 30,
            'user_agent' => 'Omeka-S-CLI/1.0',
            'follow_location' => true,
        ];
        if ($headers) {
            $http['header'] = $headers;
        }
        $context = stream_context_create(['http' => $http]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception("Failed to fetch URL: {$url}");
        }

        return $content;
    }

    /**
     * Check if source is a file
     *
     * @param string $source
     * @return bool
     */
    public static function isFile(string $source): bool
    {
        return self::detectType($source) === self::FILE;
    }

    /**
     * Check if source is a URL
     *
     * @param string $source
     * @return bool
     */
    public static function isUrl(string $source): bool
    {
        return self::detectType($source) === self::URL;
    }

    /**
     * Validate that a source exists and is accessible without fetching content
     *
     * @param string $source
     * @return bool
     * @throws InvalidArgumentException If validation fails
     */
    public static function validate(string $source): bool
    {
        $type = self::detectType($source);

        if ($type === self::FILE) {
            if (!file_exists($source)) {
                throw new InvalidArgumentException("File not found: {$source}");
            }
            if (!is_readable($source)) {
                throw new InvalidArgumentException("File is not readable: {$source}");
            }
            return true;
        }

        if ($type === self::URL) {
            if (!filter_var($source, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException("Invalid URL: {$source}");
            }
            return true;
        }

        return false;
    }
}

