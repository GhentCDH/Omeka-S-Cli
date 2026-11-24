<?php
namespace OSC\Source;

use InvalidArgumentException;

/** An absolute path on this machine. */
final class LocalPath extends Location
{
    /** @throws InvalidArgumentException When $absolutePath is not absolute */
    public static function fromAbsolute(string $absolutePath, ?string $original = null): self
    {
        if (!str_starts_with($absolutePath, '/')) {
            throw new InvalidArgumentException("'{$absolutePath}' is not an absolute path.");
        }
        return new self($absolutePath, $original);
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function folder(): string
    {
        return dirname($this->value());
    }
}
