<?php
namespace OSC\Addon;

use InvalidArgumentException;
use OSC\Blueprint\SourceForm;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\UrlCredentials;

/**
 * What to download for a module or theme, kept as separate fields instead of one string:
 * - BY_NAME: a repository lookup (omeka.org, Daniel-KM) by name, at an optional release;
 * - ZIP_URL / ZIP_FILE: a release archive;
 * - GIT: a git repository (its clone URL), at an optional ref.
 *
 * A blueprint gives the name, version and source as their own fields (fromBlueprint()); a command
 * argument is parsed by ResourceUriParser, which adds `id[:version]` to the source grammar here
 * (fromSource()), the spec's: an http(s) URL whose path ends in `.git`, `https://github.com/OWNER/REPO`,
 * `gh:owner/repo` and `git@host:path` are repositories (with an optional `#ref`); a `gh:`/`gl:` file
 * is its raw URL; any other http(s) URL, and an absolute local path, is a ZIP.
 */
final class AddonReference
{
    public const BY_NAME = 'name';
    public const ZIP_URL = 'zipUrl';
    public const ZIP_FILE = 'zipFile';
    public const GIT = 'git';

    /** An add-on name (its folder), as a repository lookup takes it. */
    private const NAME = '#\A[A-Za-z0-9][A-Za-z0-9_-]*\z#';

    /**
     * @param string      $kind     One of the constants
     * @param string|null $name     The add-on name, for BY_NAME
     * @param string|null $location The ZIP URL or path, or the clone URL
     * @param string|null $version  The release (BY_NAME) or the ref (GIT); null for a ZIP
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $name,
        public readonly ?string $location,
        public readonly ?string $version,
    ) {
    }

    /** @throws InvalidArgumentException When $name is not an add-on name */
    public static function byName(string $name, ?string $version = null): self
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("'{$name}' is not a module or theme name.");
        }
        return new self(self::BY_NAME, $name, null, $version !== '' ? $version : null);
    }

    /** A ZIP on disk, as a command argument names it (a relative path is resolved by the downloader). */
    public static function zipFile(string $path): self
    {
        return new self(self::ZIP_FILE, null, $path, null);
    }

    /**
     * A blueprint entry: without a source, a repository lookup by name and version; with one, the
     * source, where the version is the git ref unless the source pins one (`#ref`) or is a ZIP (which
     * pins the release).
     *
     * @param string|null $source The source, already resolved by the loader (a relative path is then
     *                            an absolute one)
     * @throws InvalidArgumentException
     */
    public static function fromBlueprint(string $name, ?string $source, ?string $version): self
    {
        if ($source === null) {
            return self::byName($name, $version);
        }
        return self::fromSource($source, $version);
    }

    /**
     * A source in the spec's grammar (see the class comment).
     *
     * @param string|null $version The git ref to use when the source has no `#ref`
     * @throws InvalidArgumentException When it is no add-on source
     */
    public static function fromSource(string $source, ?string $version = null): self
    {
        $shown = UrlCredentials::redact($source);
        if (str_starts_with($source, '/')) {
            return self::zipFile($source);
        }
        $form = SourceForm::of($source);
        if ($form === null || $form === SourceForm::RelativePath) {
            throw new InvalidArgumentException("'{$shown}' is not a module or theme source: use a release ZIP (URL or path) or a git repository.");
        }
        if (SourceForm::isRepository($source)) {
            return self::repository($source, $version, $shown);
        }
        $references = ReferenceResolver::withDefaults();
        if ($references->isRepoReference($source)) {
            return new self(self::ZIP_URL, null, $references->resolve($source), null);
        }
        if ($form === SourceForm::Url) {
            return new self(self::ZIP_URL, null, self::lowerScheme($source), null);
        }
        throw new InvalidArgumentException("'{$shown}' is neither a git repository nor a file in one.");
    }

    /** @throws InvalidArgumentException */
    private static function repository(string $source, ?string $version, string $shown): self
    {
        [$repository, $ref] = array_pad(explode('#', $source, 2), 2, null);
        $ref = $ref ?? ($version !== '' ? $version : null);
        if ($ref !== null && !GitRef::isValid($ref)) {
            throw new InvalidArgumentException("'{$shown}' has an invalid git ref '{$ref}'.");
        }
        if (preg_match('#\A(?:gh:|https://github\.com/)([^/]+)/([^/]+?)/?\z#i', $repository, $m) === 1 && !str_ends_with($repository, '.git')) {
            foreach ([$m[1], $m[2]] as $segment) {
                if ($segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException("'{$shown}' is not a GitHub repository.");
                }
            }
            return new self(self::GIT, null, "https://github.com/{$m[1]}/{$m[2]}.git", $ref);
        }
        return new self(self::GIT, null, self::lowerScheme($repository), $ref);
    }

    private static function lowerScheme(string $url): string
    {
        return preg_replace_callback('#\Ahttps?(?=://)#i', fn(array $m) => strtolower($m[0]), $url);
    }

    public function isGit(): bool
    {
        return $this->kind === self::GIT;
    }

    /** The add-on name of a repository lookup, or null (a ZIP or git source names it only once unpacked). */
    public function addonId(): ?string
    {
        return $this->name;
    }

    /** For output: the name[:version], or the location[#ref], without credentials. */
    public function display(): string
    {
        $text = match ($this->kind) {
            self::BY_NAME => $this->name . ($this->version !== null ? ":{$this->version}" : ''),
            self::GIT => $this->location . ($this->version !== null ? "#{$this->version}" : ''),
            default => (string) $this->location,
        };
        return UrlCredentials::redact($text);
    }
}
