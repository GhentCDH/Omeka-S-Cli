<?php
namespace OSC\Helper\Reference;

use InvalidArgumentException;

/**
 * Resolves GitHub file references to raw.githubusercontent.com download URLs.
 *
 * Recognized forms:
 *  - browser blob URL: https://github.com/{owner}/{repo}/blob/{ref}/{path}
 *  - short scheme:      gh:{owner}/{repo}[@{ref}]:{path}   (ref defaults to HEAD)
 *
 * Already-raw URLs (raw.githubusercontent.com) are intentionally not "supported": they need no
 * conversion and are handled as plain URLs by {@see ReferenceResolver}.
 */
class GitHubProvider implements RepoProvider
{
    private const RAW_HOST = 'https://raw.githubusercontent.com';

    /** Browser blob URL: captures owner, repo and the ref/path remainder. */
    private const BLOB_PATTERN = '#^https?://github\.com/([^/]+)/([^/]+)/blob/(.+)$#';

    /** Short scheme gh:owner/repo[@ref]:path — the trailing ':path' distinguishes it from a repo ref. */
    private const SHORT_PATTERN = '#^gh:([^/@:]+)/([^/@:]+)(?:@([^:]+))?:(.+)$#';

    public function supports(string $reference): bool
    {
        return preg_match(self::BLOB_PATTERN, $this->stripFragment($reference)) === 1
            || preg_match(self::SHORT_PATTERN, $reference) === 1;
    }

    public function toRawUrl(string $reference): string
    {
        $blob = $this->stripFragment($reference);
        if (preg_match(self::BLOB_PATTERN, $blob, $m) === 1) {
            return sprintf('%s/%s/%s/%s', self::RAW_HOST, $m[1], $m[2], $m[3]);
        }
        if (preg_match(self::SHORT_PATTERN, $reference, $m) === 1) {
            $ref = ($m[3] ?? '') !== '' ? $m[3] : 'HEAD';
            return sprintf('%s/%s/%s/%s/%s', self::RAW_HOST, $m[1], $m[2], $ref, $m[4]);
        }
        throw new InvalidArgumentException("Not a GitHub file reference: '{$reference}'.");
    }

    /** Drop a URL fragment (e.g. a #L5 line anchor) so it does not leak into the raw URL. */
    private function stripFragment(string $reference): string
    {
        return preg_replace('/#.*$/', '', $reference);
    }
}
