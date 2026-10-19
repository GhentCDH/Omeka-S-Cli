<?php
namespace OSC\Resource;

use Exception;

/** A resource that cannot be imported; lists every problem found, one per line. */
class ResourceImportException extends Exception
{
    /** @param string[] $problems */
    public function __construct(private string $label, private array $problems)
    {
        parent::__construct(implode("\n", array_map(fn($p) => "{$label}: {$p}", $problems)));
    }

    /** @return string[] */
    public function problems(): array
    {
        return $this->problems;
    }

    public function label(): string
    {
        return $this->label;
    }
}
