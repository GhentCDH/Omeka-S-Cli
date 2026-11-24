<?php
namespace OSC\Source\GitHost;

/**
 * GitLab file references, as raw-download URLs:
 *  - browser blob URL: https://{host}/{namespace}/-/blob/{ref}/{path}  (any host: self-hosted GitLab
 *    works too; the GitLab-specific /-/blob/ segment must be in the path, not in the query)
 *  - short scheme:     gl:{namespace}[@{ref}]:{path}                  (gitlab.com; ref defaults to HEAD)
 *
 * The namespace may contain slashes (nested groups).
 */
final class GitLabHost implements GitHost
{
    private const RAW_HOST = 'https://gitlab.com';

    /** Authority, path, and the rest (query) of an http(s) URL. */
    private const URL_PATTERN = '#\A(https?://[^/?\#]+)(/[^?\#]*)(.*)\z#is';

    /** Short scheme gl:namespace[@ref]:path. */
    private const SHORT_PATTERN = '#\Agl:([^@:]+)(?:@([^:]+))?:(.+)\z#';

    public function rawUrl(string $reference): ?string
    {
        $url = preg_replace('/#.*\z/s', '', $reference);
        if (preg_match(self::URL_PATTERN, $url, $m) === 1 && preg_match('#\A/.+/-/blob/.+\z#s', $m[2]) === 1) {
            [$namespace, $rest] = explode('/-/blob/', $m[2], 2);
            GitPathSegments::assertInside('namespace', ltrim($namespace, '/'), $reference);
            GitPathSegments::assertInside('ref and path', $rest, $reference);
            return self::lowerSchemeAndHost($m[1]) . "{$namespace}/-/raw/{$rest}" . $m[3];
        }
        if (preg_match(self::SHORT_PATTERN, $reference, $m) === 1) {
            GitPathSegments::assertInside('namespace', $m[1], $reference);
            $ref = ($m[2] ?? '') !== '' ? $m[2] : 'HEAD';
            GitPathSegments::assertRef($ref, $reference);
            GitPathSegments::assertInside('path', $m[3], $reference);
            return sprintf('%s/%s/-/raw/%s/%s', self::RAW_HOST, $m[1], $ref, $m[3]);
        }
        return null;
    }

    /** "HTTPS://u:P@GitLab.Example.org" → "https://u:P@gitlab.example.org": credentials keep their case. */
    private static function lowerSchemeAndHost(string $authority): string
    {
        [$scheme, $rest] = explode('://', $authority, 2);
        $at = strrpos($rest, '@');
        $userinfo = $at === false ? '' : substr($rest, 0, $at + 1);
        $host = $at === false ? $rest : substr($rest, $at + 1);
        return strtolower($scheme) . '://' . $userinfo . strtolower($host);
    }
}
