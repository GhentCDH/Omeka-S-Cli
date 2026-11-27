<?php
namespace OSC\Source;

use InvalidArgumentException;
use League\Uri\Uri;
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
 *   on the result. A segment of the path that decodes to `/` or `\` (or a NUL byte) is an error, from
 *   disk and from a URL. To read a local file each segment is then percent-decoded (`My%20File.pdf`
 *   names `My File.pdf`), and an unencoded `?` or `#` (a query or a fragment, which a file name cannot
 *   carry) is an error. A URL result is fetched as it is.
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

    /**
     * A relative path, resolved against the document that declares it. Its segments are checked the
     * same from disk and from a URL (decodedSegments()); what follows depends on where the document is.
     *
     * @throws SourceException
     */
    private static function resolveRelative(string $raw, Document $parent): Location
    {
        $reference = self::asUrlReference($raw);
        $decodedSegments = self::decodedSegments(self::pathOf($reference), $raw, $parent);
        return $parent->isRemote()
            ? self::resolveFromUrl($raw, $reference, $parent)
            : self::resolveFromDisk($raw, $reference, $decodedSegments, $parent);
    }

    /** $raw read as a URL reference, as a browser does: `\` separates, and `%2e` is a dot (an unreserved character). */
    private static function asUrlReference(string $raw): string
    {
        return preg_replace('/%2e/i', '.', str_replace('\\', '/', $raw));
    }

    /** The path of a URL reference: without its query or fragment. */
    private static function pathOf(string $reference): string
    {
        return preg_split('/[?#]/', $reference, 2)[0];
    }

    /**
     * Against a document read from a URL: resolved as a browser does, then kept inside the root. A
     * query or a fragment stays, since the result is fetched as it is.
     *
     * @throws SourceException
     */
    private static function resolveFromUrl(string $raw, string $reference, Document $parent): HttpUrl
    {
        $resolved = Uri::new($parent->source->value())->resolve(self::encodeWhatAUrlCannotCarry($reference))->toString();
        self::assertInsideRemoteRoot($resolved, $raw, $parent);
        return HttpUrl::fromUrl($resolved, $raw);
    }

    /**
     * Against a document on disk: the decoded path, under the document's folder, kept inside the root.
     * Only the written path is decoded, not the folder it is in.
     *
     * @param string[] $decodedSegments The path's segments, decoded (none holds a '/')
     * @throws SourceException
     */
    private static function resolveFromDisk(string $raw, string $reference, array $decodedSegments, Document $parent): LocalPath
    {
        self::assertNoQueryOrFragment($reference, $raw, $parent);
        $path = Path::normalize($parent->source->folder() . '/' . implode('/', $decodedSegments));
        self::assertInsideLocalRoot($path, $raw, $parent);
        return LocalPath::fromAbsolute($path, $raw);
    }

    /** Characters a URL cannot carry (a space, ...) may be written as they are: percent-encode them. */
    private static function encodeWhatAUrlCannotCarry(string $reference): string
    {
        return preg_replace_callback(
            "/[^A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=%]/",
            fn(array $m) => rawurlencode($m[0]),
            $reference
        );
    }

    /**
     * An unencoded `?` or `#` starts a query or a fragment, which a file name cannot carry: reading
     * `notes` for `./notes#1.pdf` would be a different file.
     *
     * @throws SourceException
     */
    private static function assertNoQueryOrFragment(string $reference, string $raw, Document $parent): void
    {
        if (strpbrk($reference, '?#') !== false) {
            throw self::error(
                $raw,
                $parent,
                "has an unencoded '?' or '#': a relative path is a URL reference, "
                . 'so percent-encode them as %3F and %23 to name a file'
            );
        }
    }

    /**
     * A remote root is a folder URL ending in '/', so a URL that starts with it is inside it.
     *
     * @throws SourceException
     */
    private static function assertInsideRemoteRoot(string $url, string $raw, Document $parent): void
    {
        if (!str_starts_with($url, $parent->root)) {
            throw self::error(
                $raw,
                $parent,
                'resolves outside the root ' . UrlCredentials::redact($parent->root),
                SourceException::OUTSIDE_ROOT
            );
        }
    }

    /**
     * Compared by its real path when it exists, so a symlink cannot lead out of the root.
     *
     * @throws SourceException
     */
    private static function assertInsideLocalRoot(string $path, string $raw, Document $parent): void
    {
        if (!Path::isInside(realpath($path) ?: $path, $parent->root)) {
            throw self::error(
                $raw,
                $parent,
                "resolves outside the root {$parent->root}; use --root to widen it",
                SourceException::OUTSIDE_ROOT
            );
        }
    }

    /**
     * The segments of a relative path, each percent-decoded. A segment that decodes to `/` or `\` (it
     * would change the path) or to a NUL byte (no file name holds one) is an error.
     *
     * @return string[]
     * @throws SourceException
     */
    private static function decodedSegments(string $path, string $raw, Document $parent): array
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);
            if (strpbrk($decoded, '/\\') !== false) {
                throw self::error($raw, $parent, "has a segment that decodes to '/' or '\\' (%2F, %5C); a file name cannot contain one");
            }
            if (str_contains($decoded, "\0")) {
                throw self::error($raw, $parent, 'has a segment that decodes to a NUL byte (%00); a file name cannot contain one');
            }
            $segments[] = $decoded;
        }
        return $segments;
    }

    /** "Source '<raw>' in '<parent>' <problem>.", without credentials; $reason as in SourceException. */
    private static function error(string $raw, Document $parent, string $problem, string $reason = SourceException::INVALID): SourceException
    {
        $redactedRaw = UrlCredentials::redact($raw);
        $redactedParent = UrlCredentials::redact($parent->source->value());
        return new SourceException("Source '{$redactedRaw}' in '{$redactedParent}' {$problem}.", $reason);
    }
}
