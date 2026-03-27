<?php
namespace OSC\ServiceProvider;

use OSC\Manager\Module\Manager as ModuleRepositoryManager;
use OSC\Repository;

class ModuleRepositoryServiceProvider
{
    public static function register(): void
    {
        $manager = ModuleRepositoryManager::getInstance();
        $manager->addRepository(new Repository\Module\OmekaDotOrg());
        $manager->addRepository(new Repository\Module\DanielKM());
    }
}
