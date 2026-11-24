<?php
namespace OSC\Source\GitHost;

/**
 * GitHub file references, as raw.githubusercontent.com URLs:
 *  - browser blob URL: https://github.com/{owner}/{repo}/blob/{ref}/{path}  (scheme and host in any case)
 *  - short scheme:     gh:{owner}/{repo}[@{ref}]:{path}                     (ref defaults to HEAD)
 *
 * A raw.githubusercontent.com URL is not one of these: it needs no conversion.
 */
final class GitHubHost implements GitHost
{
    private const RAW_HOST = 'https://raw.githubusercontent.com';

    /** Browser blob URL: captures owner, repo and the ref/path remainder. */
    private const BLOB_PATTERN = '#\Ahttps?://github\.com/([^/]+)/([^/]+)/blob/(.+)\z#i';

    /** Short scheme gh:owner/repo[@ref]:path; the trailing ':path' tells it apart from a git repository. */
    private const SHORT_PATTERN = '#\Agh:([^/@:]+)/([^/@:]+)(?:@([^:]+))?:(.+)\z#';

    public function rawUrl(string $reference): ?string
    {
        $blob = preg_replace('/#.*\z/s', '', $reference);
        if (preg_match(self::BLOB_PATTERN, $blob, $m) === 1) {
            GitPathSegments::assertInside('repository', "{$m[1]}/{$m[2]}", $reference);
            GitPathSegments::assertInside('ref and path', $m[3], $reference);
            return sprintf('%s/%s/%s/%s', self::RAW_HOST, $m[1], $m[2], $m[3]);
        }
        if (preg_match(self::SHORT_PATTERN, $reference, $m) === 1) {
            GitPathSegments::assertInside('repository', "{$m[1]}/{$m[2]}", $reference);
            $ref = ($m[3] ?? '') !== '' ? $m[3] : 'HEAD';
            GitPathSegments::assertRef($ref, $reference);
            GitPathSegments::assertInside('path', $m[4], $reference);
            return sprintf('%s/%s/%s/%s/%s', self::RAW_HOST, $m[1], $m[2], $ref, $m[4]);
        }
        return null;
    }
}
