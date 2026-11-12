<?php
namespace OSC\Resource;

use Exception;

/**
 * A resource that cannot be imported; lists every problem found, one per line. It may carry the
 * resource's identity, so that an importer can still recognise a record that already exists (which is
 * left as it is, not an error).
 */
class ResourceImportException extends Exception
{
    /** @param string[] $problems */
    public function __construct(
        private string $label,
        private array $problems,
        private ?Identity $identity = null,
        private ?string $title = null,
    ) {
        parent::__construct(implode("\n", array_map(fn($p) => "{$label}: {$p}", $problems)));
    }

    /** The identity of the resource that has the problems, when it could be determined. */
    public function identity(): ?Identity
    {
        return $this->identity;
    }

    public function title(): ?string
    {
        return $this->title;
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
