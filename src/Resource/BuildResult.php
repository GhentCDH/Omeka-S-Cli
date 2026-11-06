<?php
namespace OSC\Resource;

/** A resource ready to create: the API payload, how to find it if it exists, and the warnings. */
final class BuildResult
{
    /** @param string[] $warnings */
    public function __construct(
        private array $payload,
        private array $warnings,
        private ?Identity $identity,
        private ?string $title,
    ) {
    }

    public function payload(): array
    {
        return $this->payload;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function identity(): ?Identity
    {
        return $this->identity;
    }

    public function title(): ?string
    {
        return $this->title;
    }
}
