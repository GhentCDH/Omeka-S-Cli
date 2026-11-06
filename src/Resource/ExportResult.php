<?php
namespace OSC\Resource;

/** An exported resource and what could not be exported as-is. */
final class ExportResult
{
    /** @param string[] $warnings */
    public function __construct(private array $document, private array $warnings)
    {
    }

    public function document(): array
    {
        return $this->document;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
