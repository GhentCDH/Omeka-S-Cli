<?php
namespace OSC\Source;

use InvalidArgumentException;
use League\Uri\BaseUri;
use OSC\Helper\UrlCredentials;

/** An http(s) URL with a host; the scheme is lowercased, the rest is kept as it is. */
final class HttpUrl extends Location
{
    private const PATTERN = '#\Ahttps?://[^/?\#\s]#i';

    /** @throws InvalidArgumentException When $url is not an http(s) URL with a host */
    public static function fromUrl(string $url, ?string $original = null): self
    {
        if (!self::isHttpUrl($url)) {
            throw new InvalidArgumentException("'" . UrlCredentials::redact($url) . "' is not an http(s) URL.");
        }
        return new self(preg_replace_callback('#\Ahttps?#i', fn(array $m) => strtolower($m[0]), $url), $original);
    }

    /** Whether $value starts like an http(s) URL with a host. */
    public static function isHttpUrl(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public function isRemote(): bool
    {
        return true;
    }

    /**
     * Up to and including the last `/`, without query or fragment, normalized as a resolved source is
     * (lowercase scheme and host, no default port, no dot segments), so a root and the sources
     * resolved inside it compare in the same form.
     */
    public function folder(): string
    {
        return (string) BaseUri::from($this->value())->resolve('./');
    }
}
