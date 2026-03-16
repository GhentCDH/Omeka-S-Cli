<?php

namespace OSC\Commands\Dummy\ValueGenerator;

interface ValueGeneratorInterface
{
    /**
     * Generate a single Omeka S property value array.
     */
    public function generate(): array;
}
