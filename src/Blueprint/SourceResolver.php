<?php
namespace OSC\Blueprint;

use InvalidArgumentException;
use League\Uri\BaseUri;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\UrlCredentials;

/**
 * Turns a source written in a blueprint into a fetchable path or URL, as the shared spec's Consumers
 * section says:
 * - the form comes from the prefix (SourceForm); a value without one is rejected;
 * - a URL is kept as it is; a `gh:`/`gl:` file (or a GitHub/GitLab blob URL) becomes a raw URL, kept
 *   inside its repository; a repository is kept only for an add-on, other fields need a single file;
 * - a relative path is a URL reference, resolved against the document that declares it: `\` is a
 *   separator and `%2e` a dot, so neither `..`, `%2e%2e` nor `\` can leave the root, which is checked
 *   on the result. To read a local file each segment is then percent-decoded (`My%20File.pdf` names
 *   `My File.pdf`); a segment that decodes to `/` or `\`, and an unencoded `?` or `#` (a query or a
 *   fragment, which a file name cannot carry), are errors. A URL result is fetched as it is.
 */
final class SourceResolver
{
    public function __construct(private ReferenceResolver $references)
    {
    }

    /**
     * @param string   $raw        The value as written
     * @param Document $base       The document that declares it
     * @param bool     $repository Whether a git repository is allowed (module and theme sources); it is
     *                             returned unchanged for the add-on downloader
     * @throws SourceException
     */
    public function resolve(string $raw, Document $base, bool $repository = false): string
    {
        $shown = UrlCredentials::redact($raw);
        $where = UrlCredentials::redact($base->source);
        $form = SourceForm::of($raw);
        if ($form === null) {
            throw new SourceException("Source '{$shown}' in '{$where}' " . SourceForm::rejection($raw) . '.');
        }
        if (SourceForm::isRepository($raw)) {
            if (!$repository) {
                throw new SourceException(
                    "Source '{$shown}' in '{$where}' is a git repository; only modules and themes take one, other fields need a single file."
                );
            }
            return $raw;
        }
        if ($form !== SourceForm::RelativePath) {
            if (!$this->references->isRepoReference($raw)) {
                if ($form === SourceForm::Url) {
                    return $raw;
                }
                throw new SourceException("Source '{$shown}' in '{$where}' is neither a git repository nor a file in one.");
            }
            try {
                return $this->references->resolve($raw);
            } catch (InvalidArgumentException $e) {
                throw new SourceException(UrlCredentials::redact($e->getMessage()));
            }
        }
        return $this->resolveRelative($raw, $base, $shown, $where);
    }

    /**
     * The root of a document imported by $raw (resolved to $resolved) from $base: an import by relative
     * path keeps its importer's root; one by absolute URL or `gh:`/`gl:` is its own root, its folder.
     */
    public function importRoot(string $raw, string $resolved, Document $base): ?string
    {
        if (SourceForm::of($raw) === SourceForm::RelativePath) {
            return $base->root;
        }
        return ResourceFetcher::isUrl($resolved) ? self::folderUrl($resolved) : dirname($resolved);
    }

    /**
     * The folder URL of a URL (up to and including its last `/`, without query or fragment), normalized
     * as a resolved source is (lowercase scheme and host, no default port, no dot segments), so a root
     * and the sources resolved inside it compare in the same form.
     */
    public static function folderUrl(string $url): string
    {
        return (string) BaseUri::from($url)->resolve('./');
    }

    /** @throws SourceException */
    private function resolveRelative(string $raw, Document $base, string $shown, string $where): string
    {
        // a URL reference, as in a browser: '\' separates, and '%2e' is a dot (an unreserved character)
        $ref = preg_replace('/%2e/i', '.', str_replace('\\', '/', $raw));
        if ($base->root === null) {
            throw new SourceException("Source '{$shown}' in '{$where}' is relative, but '{$where}' has no root to resolve it in.");
        }

        if ($base->isRemote()) {
            // characters a URL cannot carry (a space, ...) may be written as they are: encode them
            $ref = preg_replace_callback(
                "/[^A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=%]/",
                fn(array $m) => rawurlencode($m[0]),
                $ref
            );
            $resolved = (string) BaseUri::from($base->source)->resolve($ref);
            if (!str_starts_with($resolved, $base->root)) {
                throw new SourceException(
                    "Source '{$shown}' in '{$where}' resolves outside the blueprint root " . UrlCredentials::redact($base->root) . '.',
                    SourceException::OUTSIDE_ROOT
                );
            }
            return $resolved;
        }

        if (strpbrk($ref, '?#') !== false) {
            throw new SourceException(
                "Source '{$shown}' in '{$where}' has an unencoded '?' or '#': a relative path is a URL reference, "
                . "so percent-encode them as %3F and %23 to name a file."
            );
        }
        $segments = [];
        foreach (explode('/', $this->normalizePath(rtrim(dirname($base->source), '/') . '/' . $ref)) as $segment) {
            $decoded = rawurldecode($segment);
            if (strpbrk($decoded, '/\\') !== false) {
                throw new SourceException(
                    "Source '{$shown}' in '{$where}' has a segment that decodes to '/' or '\\' (%2F, %5C); a file name cannot contain one."
                );
            }
            $segments[] = $decoded;
        }
        $path = implode('/', $segments);
        if (!$this->isInside($path, $base->root)) {
            throw $this->outsideRoot($shown, $where, $base->root);
        }
        return $path;
    }

    private function outsideRoot(string $shown, string $where, string $root): SourceException
    {
        return new SourceException(
            "Source '{$shown}' in '{$where}' resolves outside the blueprint root {$root}; use --root to widen it.",
            SourceException::OUTSIDE_ROOT
        );
    }

    /** Whether a local path lies inside $root; an existing path is compared by its real path (symlinks). */
    private function isInside(string $path, string $root): bool
    {
        $path = realpath($path) ?: $path;
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    /** Collapse `.` and `..` segments lexically, keeping a leading `/` (and leading `..` of a relative path). */
    private function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (!$isAbsolute) {
                    $segments[] = '..';
                }
                continue;
            }
            $segments[] = $segment;
        }
        return ($isAbsolute ? '/' : '') . implode('/', $segments);
    }
}
