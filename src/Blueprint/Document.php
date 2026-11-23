<?php
namespace OSC\Blueprint;

use OSC\Helper\ResourceFetcher;

/**
 * A file the loader reads (the blueprint or an imported file): where it is, as a fetchable path or URL,
 * and the root its relative sources must stay inside (a local directory or a URL prefix ending in `/`;
 * null: none, so a relative source is an error).
 */
final class Document
{
    public function __construct(public readonly string $source, public readonly ?string $root)
    {
    }

    public function isRemote(): bool
    {
        return ResourceFetcher::isUrl($this->source);
    }
}
