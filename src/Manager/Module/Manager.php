<?php
namespace OSC\Manager\Module;

use OSC\Manager\AbstractManager;
use OSC\Repository\Module\DanielKM;
use OSC\Repository\Module\ModuleDetails;
use OSC\Repository\Module\OmekaDotOrg;

/**
  * @extends AbstractManager<ModuleDetails>
 */
class Manager extends AbstractManager
{
    protected function registerRepositories(): void
    {
        // init module repositories
        $repositories = [
            new OmekaDotOrg(),
            new DanielKM(),
        ];

        foreach ($repositories as $repository) {
            $this->addRepository($repository);
        }
    }
}