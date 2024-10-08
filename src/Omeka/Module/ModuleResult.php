<?php
namespace OSC\Omeka\Module;

use OSC\Omeka\Module\Repository\RepositoryInterface;

class ModuleResult
{
    public function __construct(
        public ModuleRepresentation $module,
        public RepositoryInterface $repository,
        public ?ModuleVersion $version = null,
    ) {
    }
}