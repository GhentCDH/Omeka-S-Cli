<?php
namespace OSC\Helper\Reference;

use InvalidArgumentException;
use OSC\Addon\GitRef;

/**
 * Keeps a repository file reference inside its repository: the owner, repository, namespace and path
 * segments may not be empty, `.` or `..` (also percent-encoded, `%2e%2e`), nor decode to a `/` or `\`,
 * so the raw URL built from them cannot normalize into another repository.
 */
final class RepoSegments
{
    /**
     * @param string $part      What the segments are, for the message (e.g. "path")
     * @param string $segments  A `/`-separated value
     * @param string $reference The whole reference, for the message
     * @throws InvalidArgumentException
     */
    public static function assertInside(string $part, string $segments, string $reference): void
    {
        foreach (explode('/', $segments) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '' || $decoded === '.' || $decoded === '..' || strpbrk($decoded, '/\\') !== false) {
                throw new InvalidArgumentException(
                    "Reference '{$reference}' leaves its repository: the {$part} may not contain empty, '.' or '..' segments."
                );
            }
        }
    }

    /** @throws InvalidArgumentException When $ref is not a valid git ref */
    public static function assertRef(string $ref, string $reference): void
    {
        if (!GitRef::isValid($ref)) {
            throw new InvalidArgumentException("Reference '{$reference}' has an invalid git ref '{$ref}'.");
        }
    }
}
