<?php
namespace OSC\Source;

use InvalidArgumentException;
use League\Uri\BaseUri;
use OSC\Helper\Path;
use OSC\Helper\UrlCredentials;

/**
 * Turns a source written in a file (a blueprint, an imported file, a command's config file) into a
 * Location, as the shared spec's Consumers section says:
 * - the form comes from the prefix (SourceForm); a value without one is rejected;
 * - a URL is kept as it is; a `gh:`/`gl:` file (or a GitHub/GitLab blob URL) becomes a raw URL, kept
 *   inside its git repository; a git repository is accepted only for an add-on (addon()), other
 *   fields need a single file (file());
 * - a relative path is a URL reference, resolved against the document that declares it: `\` is a
 *   separator and `%2e` a dot, so neither `..`, `%2e%2e` nor `\` can leave the root, which is checked
 *   on the result. To read a local file each segment is then percent-decoded (`My%20File.pdf` names
 *   `My File.pdf`); a segment that decodes to `/` or `\`, and an unencoded `?` or `#` (a query or a
 *   fragment, which a file name cannot carry), are errors. A URL result is fetched as it is.
 *
 * Every value returned keeps the input as original(), so messages can name what was written.
 */
final class DocumentSourceResolver
{
    /**
     * A source that must be a single file.
     *
     * @param string   $raw    The value as written
     * @param Document $parent The file that declares it
     * @throws SourceException
     */
    public static function file(string $raw, Document $parent): Location
    {
        $form = SourceForm::of($raw);
        if ($form === null) {
            throw self::error($raw, $parent, SourceForm::rejection($raw));
        }
        if (SourceForm::isRepository($raw)) {
            throw self::error($raw, $parent, 'is a git repository; only modules and themes take one, other fields need a single file');
        }
        if ($form === SourceForm::RelativePath) {
            return self::resolveRelative($raw, $parent);
        }
        try {
            $gitFile = GitFile::rawUrl($raw);
        } catch (InvalidArgumentException $e) {
            throw new SourceException(UrlCredentials::redact($e->getMessage()));
        }
        if ($gitFile !== null) {
            return $gitFile;
        }
        if ($form === SourceForm::Url) {
            return HttpUrl::fromUrl($raw, $raw);
        }
        throw self::error($raw, $parent, 'is neither a git repository nor a file in one');
    }

    /**
     * A module or theme source: a git repository, or else a file (a release ZIP).
     *
     * @throws SourceException
     */
    public static function addon(string $raw, Document $parent): Location|GitRepository
    {
        if (!SourceForm::isRepository($raw)) {
            return self::file($raw, $parent);
        }
        try {
            return GitRepository::parse($raw);
        } catch (InvalidArgumentException $e) {
            throw new SourceException(UrlCredentials::redact($e->getMessage()));
        }
    }

    /**
     * The root of a document imported by $raw (resolved to $resolved) from $parent: an import by
     * relative path keeps its importer's root; one by absolute URL or `gh:`/`gl:` is its own root, its
     * folder.
     */
    public static function importRoot(string $raw, Location $resolved, Document $parent): string
    {
        return SourceForm::of($raw) === SourceForm::RelativePath ? $parent->root : $resolved->folder();
    }

    /**
     * The inverse of file() for a local file: what a file in $folder writes to name $target, a
     * relative path with each segment percent-encoded (a URL reference, see resolveRelative()).
     */
    public static function reference(LocalPath $target, string $folder): string
    {
        return implode('/', array_map('rawurlencode', explode('/', Path::relative($target->value(), $folder))));
    }

    /** @throws SourceException */
    private static function resolveRelative(string $raw, Document $parent): Location
    {
        // a URL reference, as in a browser: '\' separates, and '%2e' is a dot (an unreserved character)
        $ref = preg_replace('/%2e/i', '.', str_replace('\\', '/', $raw));

        if ($parent->isRemote()) {
            // characters a URL cannot carry (a space, ...) may be written as they are: encode them
            $ref = preg_replace_callback(
                "/[^A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=%]/",
                fn(array $m) => rawurlencode($m[0]),
                $ref
            );
            $resolved = (string) BaseUri::from($parent->source->value())->resolve($ref);
            // a remote root is a folder URL ending in '/', so a prefix is inside it
            if (!str_starts_with($resolved, $parent->root)) {
                throw self::error(
                    $raw,
                    $parent,
                    'resolves outside the root ' . UrlCredentials::redact($parent->root),
                    SourceException::OUTSIDE_ROOT
                );
            }
            return HttpUrl::fromUrl($resolved, $raw);
        }

        if (strpbrk($ref, '?#') !== false) {
            throw self::error(
                $raw,
                $parent,
                "has an unencoded '?' or '#': a relative path is a URL reference, "
                . 'so percent-encode them as %3F and %23 to name a file'
            );
        }
        $segments = [];
        foreach (explode('/', Path::normalize($parent->source->folder() . '/' . $ref)) as $segment) {
            $decoded = rawurldecode($segment);
            if (strpbrk($decoded, '/\\') !== false) {
                throw self::error($raw, $parent, "has a segment that decodes to '/' or '\\' (%2F, %5C); a file name cannot contain one");
            }
            $segments[] = $decoded;
        }
        $path = implode('/', $segments);
        // compared by its real path when it exists, so a symlink cannot lead out of the root
        if (!Path::isInside(realpath($path) ?: $path, $parent->root)) {
            throw self::error(
                $raw,
                $parent,
                "resolves outside the root {$parent->root}; use --root to widen it",
                SourceException::OUTSIDE_ROOT
            );
        }
        return LocalPath::fromAbsolute($path, $raw);
    }

    /** "Source '<raw>' in '<parent>' <problem>.", without credentials; $reason as in SourceException. */
    private static function error(string $raw, Document $parent, string $problem, string $reason = SourceException::INVALID): SourceException
    {
        $redactedRaw = UrlCredentials::redact($raw);
        $redactedParent = UrlCredentials::redact($parent->source->value());
        return new SourceException("Source '{$redactedRaw}' in '{$redactedParent}' {$problem}.", $reason);
    }
}
