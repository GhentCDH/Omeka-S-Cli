<?php
namespace OSC\Addon;

use InvalidArgumentException;
use OSC\Source\GitRepository;
use OSC\Source\NamedAddon;
use OSC\Source\Location;
use OSC\Source\SourceForm;

/**
 * What to download for a module or theme, as one typed source:
 * - NamedAddon: a repository lookup (omeka.org, Daniel-KM) by name, at an optional release;
 * - Location: a release ZIP, on disk (LocalPath) or at a URL (HttpUrl);
 * - GitRepository: a git repository to clone, at an optional ref.
 *
 * A command argument is parsed by CliSourceResolver::addon(); a blueprint entry gives its name,
 * version and source as separate fields (fromBlueprint(), or fromLoaded() once the loader resolved
 * the source to a string). Nothing parses a glued "name:version" string out of a source.
 */
final class AddonReference
{
    private function __construct(public readonly NamedAddon|Location|GitRepository $source)
    {
    }

    /** @throws InvalidArgumentException When $name is not an add-on name */
    public static function byName(string $name, ?string $version = null): self
    {
        return new self(new NamedAddon($name, $version));
    }

    public static function named(NamedAddon $addon): self
    {
        return new self($addon);
    }

    public static function zip(Location $zip): self
    {
        return new self($zip);
    }

    public static function git(GitRepository $repository): self
    {
        return new self($repository);
    }

    /**
     * A blueprint entry: without a source, a repository lookup by name and version; with one, that
     * source, where the version is the git ref unless the source pins one (`#ref`) or is a ZIP (which
     * pins the release).
     *
     * @throws InvalidArgumentException When $name is not an add-on name, or $version an invalid ref
     */
    public static function fromBlueprint(string $name, Location|GitRepository|null $source, ?string $version): self
    {
        if ($source === null) {
            return self::byName($name, $version);
        }
        if ($source instanceof GitRepository) {
            return self::git($source->ref() === null ? $source->withRef($version) : $source);
        }
        return self::zip($source);
    }

    /**
     * fromBlueprint() for an entry of a loaded blueprint, whose source the loader already resolved
     * and stored as a string: an absolute path, an http(s) URL, or a git repository's clone URL with
     * an optional #ref. Anything else (a relative path, a gh: shortcut, "name:version") is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function fromLoaded(string $name, ?string $source, ?string $version): self
    {
        if ($source === null) {
            return self::byName($name, $version);
        }
        $typed = SourceForm::isRepository($source) ? GitRepository::parse($source) : Location::fromResolved($source);
        return self::fromBlueprint($name, $typed, $version);
    }

    public function isGit(): bool
    {
        return $this->source instanceof GitRepository;
    }

    /** The add-on name of a repository lookup, or null (a ZIP or git source names it only once unpacked). */
    public function addonId(): ?string
    {
        return $this->source instanceof NamedAddon ? $this->source->name : null;
    }

    /** For output, without credentials: name[:version], or the source as written and its real location. */
    public function display(): string
    {
        return $this->source->display();
    }
}
