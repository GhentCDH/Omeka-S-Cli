<?php
namespace OSC\Source;

use InvalidArgumentException;
use OSC\Addon\AddonReference;
use OSC\Helper\Path;
use OSC\Helper\UrlCredentials;

/**
 * What a source typed on the command line (an argument, an option, a --media spec) is. Unlike a
 * source written in a file (DocumentSourceResolver), a path is taken literally (`My%20File.pdf` is that name)
 * and may be absolute; a relative path is relative to the working directory.
 */
final class CliSourceResolver
{
    /** Any other URI scheme (two letters or more, so a drive letter such as C: stays a path). */
    private const SCHEME = '#\A[A-Za-z][A-Za-z0-9+.-]+:#';

    /**
     * A file: a local path, an http(s) URL, or a file in a git repository (gh:, gl:, a blob URL),
     * which is fetched from its raw URL. The returned Location keeps $value as original().
     *
     * @throws InvalidArgumentException When $value is empty, a git repository or another scheme
     */
    public static function file(string $value, string $cwd): Location
    {
        if ($value === '') {
            throw new InvalidArgumentException('The source is empty: give a path, an http(s) URL or gh:/gl:.');
        }
        $gitFile = GitFile::rawUrl($value);
        if ($gitFile !== null) {
            return $gitFile;
        }
        $redactedValue = UrlCredentials::redact($value);
        if (SourceForm::isRepository($value)) {
            throw new InvalidArgumentException("'{$redactedValue}' is a git repository; a file is expected here.");
        }
        if (HttpUrl::isHttpUrl($value)) {
            return HttpUrl::fromUrl($value, $value);
        }
        if (preg_match(self::SCHEME, $value) === 1) {
            throw new InvalidArgumentException("Unsupported source '{$redactedValue}': use a path, an http(s) URL or gh:/gl:.");
        }
        return LocalPath::fromAbsolute(Path::normalize(Path::toAbsolutePath($value, $cwd)), $value);
    }

    /**
     * A module or theme argument: a name with an optional release (`Common:3.4.93`), a git repository
     * (`gh:o/r#ref`, `….git`, `https://github.com/o/r`, `git@host:path`), or a release ZIP as a file
     * (a local path, any http(s) URL, a file in a git repository). A relative path must end in `.zip`,
     * so a mistyped name is not read as a file.
     *
     * @throws InvalidArgumentException
     */
    public static function addon(string $value, string $cwd): AddonReference
    {
        $named = NamedAddon::tryParse($value);
        if ($named !== null) {
            return AddonReference::named($named);
        }
        if (SourceForm::isRepository($value)) {
            return AddonReference::git(GitRepository::parse($value));
        }
        if (!str_starts_with($value, '/') && SourceForm::of($value) === SourceForm::RelativePath
            && preg_match('/\.zip\z/i', $value) !== 1) {
            throw new InvalidArgumentException("Could not determine argument type: '{$value}'");
        }
        return AddonReference::zip(self::file($value, $cwd));
    }
}
