<?php
namespace OSC\Omeka\Module;

class ModuleRepresentation
{
    /**
     * @param ModuleVersion[] $versions
     */
    public function __construct(
        public string $id,
        public string $latestVersion,
        public array $versions,
        public ?string $description = null,
        public ?string $link = null,
        public ?string $owner = null,
        public ?string $tags = null,
        public ?array $dependencies = []
    ) {
    }
}