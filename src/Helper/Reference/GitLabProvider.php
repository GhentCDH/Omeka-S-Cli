<?php
namespace OSC\Helper\Reference;

use InvalidArgumentException;

/**
 * Resolves GitLab file references to raw-download URLs.
 *
 * Recognized forms:
 *  - browser blob URL: https://{host}/{namespace}/-/blob/{ref}/{path}
 *  - short scheme:      gl:{namespace}[@{ref}]:{path}   (gitlab.com; ref defaults to HEAD)
 *
 * Blob URLs are matched by the GitLab-specific "/-/blob/" path segment rather than by host, so
 * self-hosted GitLab instances work without any extra configuration. The namespace may contain
 * slashes (nested groups).
 */
class GitLabProvider implements RepoProvider
{
    private const RAW_HOST = 'https://gitlab.com';

    /** Browser blob URL, identified by the GitLab-specific /-/blob/ segment (any host). */
    private const BLOB_PATTERN = '#^https?://[^/]+/.+/-/blob/.+$#';

    /** Short scheme gl:namespace[@ref]:path — namespace may include slashes for nested groups. */
    private const SHORT_PATTERN = '#^gl:([^@:]+)(?:@([^:]+))?:(.+)$#';

    public function supports(string $reference): bool
    {
        return preg_match(self::BLOB_PATTERN, $this->stripFragment($reference)) === 1
            || preg_match(self::SHORT_PATTERN, $reference) === 1;
    }

    public function toRawUrl(string $reference): string
    {
        $blob = $this->stripFragment($reference);
        if (preg_match(self::BLOB_PATTERN, $blob) === 1) {
            return preg_replace('#/-/blob/#', '/-/raw/', $blob, 1);
        }
        if (preg_match(self::SHORT_PATTERN, $reference, $m) === 1) {
            $ref = ($m[2] ?? '') !== '' ? $m[2] : 'HEAD';
            return sprintf('%s/%s/-/raw/%s/%s', self::RAW_HOST, $m[1], $ref, $m[3]);
        }
        throw new InvalidArgumentException("Not a GitLab file reference: '{$reference}'.");
    }

    /** Drop a URL fragment (e.g. a #L5 line anchor) so it does not leak into the raw URL. */
    private function stripFragment(string $reference): string
    {
        return preg_replace('/#.*$/', '', $reference);
    }
}
