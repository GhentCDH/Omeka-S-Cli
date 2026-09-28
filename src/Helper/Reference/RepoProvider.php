<?php
namespace OSC\Helper\Reference;

/**
 * Recognizes host-specific ways of pointing at a single file in a git repository and converts them
 * to a raw-download URL.
 *
 * A provider handles both a host's browser "blob" URLs and a compact short scheme. Implementations
 * are pure string transformations (no network), so they are cheap and unit-testable, and new hosts
 * can be added by implementing this interface and registering it with {@see ReferenceResolver}.
 */
interface RepoProvider
{
    /** Whether this provider recognizes the given reference (a blob URL or its short scheme). */
    public function supports(string $reference): bool;

    /**
     * Convert a recognized reference into a raw-download URL.
     *
     * @throws \InvalidArgumentException If the reference is not one this provider supports.
     */
    public function toRawUrl(string $reference): string;
}
