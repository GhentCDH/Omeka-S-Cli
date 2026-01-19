<?php
namespace OSC\Manager\Vocabulary;

use OSC\Manager\AbstractManager;
use OSC\Repository\Vocabulary\GhentCDH;
use OSC\Repository\Vocabulary\LOV;
use OSC\Repository\Vocabulary\VocabularyItem;

/**
  * @extends AbstractManager<VocabularyItem>
 */
class Manager extends AbstractManager
{
    protected function registerRepositories(): void
    {
        // init module repositories
        $repositories = [
            new GhentCDH(),
            new LOV(),
        ];

        foreach ($repositories as $repository) {
            $this->addRepository($repository);
        }
    }
}