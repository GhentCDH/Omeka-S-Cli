<?php
namespace OSC\Omeka\Module;

class ModuleVersion
{
    public function __construct(
        public string $version,
        public string $created,
        public string $downloadUrl,
        public ?string $omekaVersionConstraint = null,
        public array $dependencies = [],
    ) {
    }
}