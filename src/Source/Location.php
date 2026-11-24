<?php
namespace OSC\Source;

use InvalidArgumentException;
use OSC\Helper\UrlCredentials;

/**
 * Something the CLI can fetch: a local path (LocalPath) or an http(s) URL (HttpUrl). Every source a
 * user names ends as one of these, whatever form it was written in (a relative path, a gh:/gl:/blob
 * shortcut, a URL); the parsers in this namespace decide which, and keep the input as original() so
 * messages can name what the user wrote.
 */
abstract class Location
{
    protected function __construct(private readonly string $value, private readonly ?string $original)
    {
    }

    /** The absolute path or the URL. */
    public function value(): string
    {
        return $this->value;
    }

    abstract public function isRemote(): bool;

    /**
     * The folder it is in: the root of a document read from here unless --root widens it. A local
     * folder has no trailing `/`; a folder URL ends in `/` (see HttpUrl::folder()).
     */
    abstract public function folder(): string;

    /** The input as written (a relative path, a gh: shortcut, ...); null when made by fromResolved(). */
    public function original(): ?string
    {
        return $this->original;
    }

    /** For messages, without credentials: "'<original>' (<value>)", or the value alone when they are the same. */
    public function display(): string
    {
        $value = UrlCredentials::redact($this->value);
        if ($this->original === null || $this->original === $this->value) {
            return $value;
        }
        return "'" . UrlCredentials::redact($this->original) . "' ({$value})";
    }

    /**
     * The boundary factory, for a value that was resolved before and stored as a string (a loaded
     * blueprint, a config object, an Omeka request): only an absolute path or an http(s) URL.
     *
     * @throws InvalidArgumentException For anything else
     */
    public static function fromResolved(string $value): Location
    {
        if (str_starts_with($value, '/')) {
            return LocalPath::fromAbsolute($value);
        }
        if (HttpUrl::isHttpUrl($value)) {
            return HttpUrl::fromUrl($value);
        }
        throw new InvalidArgumentException(
            "'" . UrlCredentials::redact($value) . "' is not a resolved source: an absolute path or an http(s) URL."
        );
    }
}
