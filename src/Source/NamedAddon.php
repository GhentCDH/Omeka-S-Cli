<?php
namespace OSC\Source;

use InvalidArgumentException;

/**
 * A module or theme named to be looked up in the repositories (omeka.org, Daniel-KM), at an optional
 * release. Only a command argument writes it as one string (`Common:3.4.93`, tryParse()); a blueprint
 * entry gives name and version as separate fields.
 */
final class NamedAddon
{
    /** An add-on name (its folder), as a repository lookup takes it. */
    private const NAME = '[A-Za-z0-9][A-Za-z0-9_-]*';

    /** A release, as a command argument writes it after the `:`. */
    private const VERSION = '[A-Za-z0-9_.-]+';

    public readonly ?string $version;

    /** @throws InvalidArgumentException When $name is not an add-on name */
    public function __construct(public readonly string $name, ?string $version = null)
    {
        if (preg_match('#\A' . self::NAME . '\z#', $name) !== 1) {
            throw new InvalidArgumentException("'{$name}' is not a module or theme name.");
        }
        $this->version = $version !== '' ? $version : null;
    }

    /** `name[:version]` (display()'s inverse), or null when $value is another form. */
    public static function tryParse(string $value): ?self
    {
        if (preg_match('#\A(' . self::NAME . ')(?::(' . self::VERSION . '))?\z#', $value, $m) !== 1) {
            return null;
        }
        return new self($m[1], $m[2] ?? null);
    }

    /** name[:version], as a command argument writes it. */
    public function display(): string
    {
        return $this->name . ($this->version !== null ? ":{$this->version}" : '');
    }
}
