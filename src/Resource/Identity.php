<?php
namespace OSC\Resource;

/**
 * How to recognize a resource that already exists on the instance: a property and its value, or
 * (term null) the display title.
 */
final class Identity
{
    public function __construct(
        public readonly ?string $term,
        public readonly string $value,
    ) {
    }

    public function describe(): string
    {
        return $this->term === null ? "title \"{$this->value}\"" : "{$this->term} = \"{$this->value}\"";
    }
}
