<?php
namespace OSC\Manager\Theme;

use OSC\Manager\AbstractManager;
use OSC\Repository\Theme\OmekaDotOrg;
use OSC\Repository\Theme\ThemeDetails;

/**
  * @extends AbstractManager<ThemeDetails>
 */
class Manager extends AbstractManager
{
    protected function registerRepositories(): void
    {
        // init module repositories
        $repositories = [
            new OmekaDotOrg(),
        ];

        foreach ($repositories as $repository) {
            $this->addRepository($repository);
        }
    }
}