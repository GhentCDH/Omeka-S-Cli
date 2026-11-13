<?php
namespace OSC\Omeka;

use Closure;
use OSC\Helper\ResourceFetcher;
use UnexpectedValueException;

class OmekaDotOrgApi
{
    private const OMEKA_VERSION_API_URL = 'https://api.omeka.org/latest-version-s';

    private static array $instances = [];

    /** @var Closure(string): string Fetches a URL */
    private Closure $fetch;

    /** @param Closure(string): string|null $fetch Fetches a URL (default ResourceFetcher::fetch) */
    public function __construct(?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? fn(string $url): string => ResourceFetcher::fetch($url);
    }

    public static function getInstance(): static
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    /**
     * @throws \Exception When the API cannot be reached (ResourceFetcher's timeouts apply)
     * @throws UnexpectedValueException When the answer is not an x.y.z version (e.g. an error page)
     */
    public function getLatestOmekaVersion(): string
    {
        $answer = trim(($this->fetch)(self::OMEKA_VERSION_API_URL));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $answer)) {
            throw new UnexpectedValueException(
                "The Omeka S version API returned an unexpected answer: '" . mb_substr($answer, 0, 40) . "'."
            );
        }
        return $answer;
    }

    /** The release ZIP of an Omeka S version, as published on GitHub. */
    public static function coreReleaseUrl(string $version): string
    {
        return "https://github.com/omeka/omeka-s/releases/download/v{$version}/omeka-s-{$version}.zip";
    }
}
