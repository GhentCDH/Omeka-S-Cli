<?php
namespace OSC\Helper;

use Exception;
use InvalidArgumentException;
use OSC\Source\HttpUrl;
use OSC\Source\LocalPath;
use OSC\Source\Location;

/**
 * Fetches a Location: reads a local file, or downloads an http(s) URL. It only fetches: what a string
 * is (a path, a URL, a gh: shortcut, ...) is decided by the parsers in OSC\Source, which hand it a
 * LocalPath or an HttpUrl.
 *
 * ```php
 * $content = ResourceFetcher::fetch(CliSourceResolver::file($argument, $cwd));
 * $data = ResourceFetcher::fetchJson(HttpUrl::fromUrl('https://example.org/data.json'));
 * ```
 */
class ResourceFetcher
{
    /**
     * Fetch content from a file or URL
     *
     * @param Location $location A local file or a URL; errors name it as the user wrote it
     * @param string[] $headers  Request headers as "Name: value" strings, applied to a URL only
     * @return string The content
     * @throws InvalidArgumentException If the file is inaccessible
     * @throws Exception If fetching fails
     */
    public static function fetch(Location $location, array $headers = []): string
    {
        return $location instanceof HttpUrl
            ? self::fetchFromUrl($location, $headers)
            : self::fetchFromFile($location);
    }

    /** Fetch content from a file or URL and decode json */
    public static function fetchJson(Location $location): mixed
    {
        $content = self::fetch($location);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON from source: {$location->display()}. Error: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Copy a file, or download a URL, to $target, streaming it (no memory limit). A download is
     * aborted when it stalls, not after a fixed time, so a large release on a slow link still arrives:
     * with cURL when it stays below 1 KB/s for 60 s, without cURL when no data arrives for 60 s.
     *
     * @param string[] $headers Request headers for a URL (e.g. "Accept: text/turtle")
     * @return string|null The response Content-Type of a URL (lowercase, without parameters); null for a file
     * @throws InvalidArgumentException If a file source does not exist
     * @throws Exception If copying or downloading fails
     */
    public static function download(Location $location, string $target, array $headers = []): ?string
    {
        $locationValue = $location->value();
        $locationDisplay = $location->display();
        if (!$location instanceof HttpUrl) {
            if (!is_file($locationValue)) {
                throw new InvalidArgumentException("File not found: {$locationDisplay}");
            }
            Path::copyFile($locationValue, $target, "Failed to copy '{$locationValue}' to '{$target}'");
            return null;
        }

        if (!function_exists('curl_init')) {
            return self::downloadWithStreamWrapper($location, $target, $headers);
        }

        $handle = Path::checked(fn() => fopen($target, 'wb'), "Failed to write '{$target}'");
        $ch = curl_init($locationValue);
        curl_setopt_array($ch, [CURLOPT_FILE => $handle, CURLOPT_HTTPHEADER => $headers] + self::curlDownloadOptions());
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($handle);

        if ($ok === false) {
            @unlink($target);
            throw new Exception("Failed to download {$locationDisplay}: {$error}");
        }
        return is_string($type) && $type !== '' ? strtolower(trim(explode(';', $type)[0])) : null;
    }

    /**
     * The transports' own check (defence in depth; an HttpUrl is already one): only an http(s) URL.
     * PHP's stream wrappers and libcurl would open any other scheme (php://, phar://, ftp://, gopher://,
     * ...), reading local files or reaching other services.
     *
     * @throws InvalidArgumentException When $url is not a valid http(s) URL
     */
    private static function assertHttpUrl(string $url): void
    {
        if (!HttpUrl::isHttpUrl($url)) {
            throw new InvalidArgumentException("Unsupported source '{$url}': use an http(s) URL or a file path.");
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }
    }

    /**
     * Fetch content from a file
     *
     * @throws InvalidArgumentException If file doesn't exist or is not readable
     * @throws Exception If reading fails
     */
    protected static function fetchFromFile(LocalPath $file): string
    {
        if (!file_exists($file->value())) {
            throw new InvalidArgumentException("File not found: {$file->display()}");
        }

        if (!is_readable($file->value())) {
            throw new InvalidArgumentException("File is not readable: {$file->display()}");
        }

        return Path::readFile($file->value(), "Could not read {$file->display()}");
    }

    /**
     * Fetch content from a URL
     *
     * @param string[] $headers Request headers as "Name: value" strings
     * @throws InvalidArgumentException If URL is invalid
     * @throws Exception If fetching fails
     */
    protected static function fetchFromUrl(HttpUrl $url, array $headers = []): string
    {
        self::assertHttpUrl($url->value());

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
     * @param string[] $headers Request headers as "Name: value" strings
     * @throws Exception If cURL request fails
     */
    protected static function fetchWithCurl(HttpUrl $url, array $headers = []): string
    {
        $ch = curl_init($url->value());

        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers] + self::curlFetchOptions());

        $content = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($content === false) {
            throw new Exception("Failed to fetch {$url->display()}: {$error}");
        }

        if ($httpCode >= 400) {
            throw new Exception("HTTP error {$httpCode} when fetching {$url->display()}");
        }

        return $content;
    }

    /**
     * Fetch content using the http stream wrapper
     *
     * Fallback for hosts without ext-curl. The wrapper does not expose the HTTP status
     * code as a return value, so failures are reported without it.
     *
     * @param string[] $headers Request headers as "Name: value" strings
     * @throws Exception If the request fails
     */
    protected static function fetchWithStreamWrapper(HttpUrl $url, array $headers = []): string
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

        $content = @file_get_contents($url->value(), false, $context);

        if ($content === false) {
            throw new Exception("Failed to fetch {$url->display()}");
        }

        return $content;
    }

    /**
     * Why a body of $copied bytes is incomplete according to the response headers, or null when it
     * is complete or nothing can be told (no Content-Length, or an encoded body).
     *
     * @param array<string,string> $headers lowercase header name => value (see finalHeaders())
     */
    protected static function truncation(int $copied, array $headers): ?string
    {
        $length = $headers['content-length'] ?? '';
        if (isset($headers['content-encoding']) || !ctype_digit($length) || $copied >= (int) $length) {
            return null;
        }
        return "the connection closed after {$copied} of {$length} bytes";
    }

    /** The cURL options of fetch(), apart from the request headers. */
    protected static function curlFetchOptions(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Omeka-S-CLI/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ] + self::curlHttpOnly();
    }

    /** Limit cURL, and any redirect it follows, to http(s): libcurl also speaks file, gopher, dict, smb, ... */
    private static function curlHttpOnly(): array
    {
        return [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
    }

    /** The cURL options of download(), apart from the target and the request headers. */
    protected static function curlDownloadOptions(): array
    {
        return [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            // abort a stalled transfer (below 1 KB/s for 60 s) instead of capping the whole download
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_USERAGENT => 'Omeka-S-CLI/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => true,
        ] + self::curlHttpOnly();
    }

    /**
     * Download a URL to $target with PHP's http stream wrapper, streaming it (the transport without
     * cURL).
     *
     * @param string[] $headers Request headers as "Name: value" strings
     * @return string|null The response Content-Type (lowercase, without parameters), if any
     * @throws Exception If the URL is invalid or the download fails; $target is then removed
     */
    protected static function downloadWithStreamWrapper(HttpUrl $url, string $target, array $headers = []): ?string
    {
        self::assertHttpUrl($url->value());
        if (!ini_get('allow_url_fopen')) {
            throw new Exception("Cannot fetch URL: cURL is not available and allow_url_fopen is disabled");
        }

        // timeout is an idle timeout here: the stream wrapper has no total limit. The wrapper never
        // switches protocol on a redirect: a Location such as file:///etc/x is requested as a path on
        // the same http(s) server.
        $http = ['timeout' => 60, 'user_agent' => 'Omeka-S-CLI/1.0', 'follow_location' => true];
        if ($headers) {
            $http['header'] = $headers;
        }
        $in = @fopen($url->value(), 'rb', false, stream_context_create(['http' => $http]));
        if ($in === false) {
            // "fopen(<url>): Failed to open stream: HTTP request failed! HTTP/1.1 404 Not Found\r\n" -> the reason
            $reason = trim(preg_replace('/^.*?Failed to open stream: /is', '', error_get_last()['message'] ?? ''));
            @unlink($target);
            throw new Exception("Failed to download {$url->display()}" . ($reason !== '' ? ": {$reason}" : ''));
        }
        try {
            $out = Path::checked(fn() => fopen($target, 'wb'), "Failed to write '{$target}'");
            $copied = stream_copy_to_stream($in, $out);
            fclose($out);
            if ($copied === false) {
                @unlink($target);
                throw new Exception("Failed to download {$url->display()}");
            }
            $final = self::finalHeaders(stream_get_meta_data($in)['wrapper_data'] ?? []);
            // the wrapper reports a connection closed early as the end of the body
            $truncation = self::truncation($copied, $final);
            if ($truncation !== null) {
                @unlink($target);
                throw new Exception("Failed to download {$url->display()}: {$truncation}");
            }
            $type = strtolower(trim(explode(';', $final['content-type'] ?? '')[0]));
            return $type !== '' ? $type : null;
        } finally {
            fclose($in);
        }
    }

    /**
     * The headers of the final response in a stream wrapper's header list, which holds the status
     * line and headers of every redirect in order.
     *
     * @param mixed[] $headerLines e.g. ['HTTP/1.1 302 Found', 'Location: …', 'HTTP/1.1 200 OK', 'Content-Type: …']
     * @return array<string,string> lowercase header name => value
     */
    protected static function finalHeaders(array $headerLines): array
    {
        $headers = [];
        foreach ($headerLines as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/#i', $line)) {
                $headers = []; // a new response starts
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }
}
