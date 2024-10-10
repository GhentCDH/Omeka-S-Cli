<?php
namespace OSC\Repository\Module;

class ModuleRepresentation
{
    /**
     * @param ModuleVersion[] $versions
     */
    public function __construct(
        // lowercase version of the module name
        public string $id, 
        // the name of the module
        public string $dirname,
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