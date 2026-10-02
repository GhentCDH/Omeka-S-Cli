<?php
namespace OSC\Helper\Reference;

use League\Uri\BaseUri;
use OSC\Helper\ResourceFetcher;

/**
 * Resolves a file reference into a concrete, fetchable path or URL.
 *
 * It handles two things a plain fetcher cannot:
 *  1. Repo-aware references (browser "blob" URLs and the gh:/gl: short schemes) are converted to
 *     raw-download URLs via the registered {@see RepoProvider}s.
 *  2. Relative references (a filename, a sub-path, or one using ../) are resolved against a base.
 *     For URL bases this uses RFC 3986 resolution (via league/uri), so ./ and ../ segments are
 *     normalized correctly and the result stays within the same repository.
 *
 * The class is stateless and reusable: it is used both for blueprint `$import` references and for
 * asset paths inside a blueprint, and any other loader can reuse it the same way.
 */
class ReferenceResolver
{
    /** @param RepoProvider[] $providers Ordered list; the first that supports a reference wins. */
    public function __construct(private array $providers = [])
    {
    }

    /** Build a resolver with the default providers (GitHub, GitLab). */
    public static function withDefaults(): self
    {
        return new self([new GitHubProvider(), new GitLabProvider()]);
    }

    /**
     * Resolve a reference to a fetchable path or URL.
     *
     * @param string      $reference The reference to resolve (repo-aware, absolute, or relative).
     * @param string|null $base      The source containing the reference, for relative resolution.
     * @return string A local path or a URL suitable for {@see ResourceFetcher::fetch()}.
     */
    public function resolve(string $reference, ?string $base = null): string
    {
        $reference = trim($reference);

        // 1. repo-aware reference (blob URL / short scheme) -> raw URL
        $raw = $this->toRawUrl($reference);
        if ($raw !== null) {
            return $raw;
        }

        // 2. already-absolute references need no base
        if (ResourceFetcher::isUrl($reference) || str_starts_with($reference, '/')) {
            return $reference;
        }

        // 3. relative reference: resolve against the (normalized) base
        if ($base === null || $base === '') {
            return $reference;
        }
        $base = $this->toRawUrl($base) ?? $base;

        if (ResourceFetcher::isUrl($base)) {
            return (string) BaseUri::from($base)->resolve($reference);
        }
        return rtrim(dirname($base), '/') . '/' . $reference;
    }

    /** Convert a repo-aware reference to a raw URL, or null if no provider recognizes it. */
    private function toRawUrl(string $reference): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($reference)) {
                return $provider->toRawUrl($reference);
            }
        }
        return null;
    }
}
