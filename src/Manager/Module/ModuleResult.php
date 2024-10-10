<?php
namespace OSC\Manager\Module;

use OSC\Repository\Module\ModuleRepositoryInterface;
use OSC\Repository\Module\ModuleRepresentation;
use OSC\Repository\Module\ModuleVersion;

class ModuleResult
{
    public function __construct(
        public ModuleRepresentation      $module,
        public ModuleRepositoryInterface $repository,
        public ?ModuleVersion            $version = null,
    ) {
    }
}