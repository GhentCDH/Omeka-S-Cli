<?php
namespace OSC\Commands\Module;

use OSC\Manager\Module\Manager as ModuleRepositoryManager;

class RepositoriesCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:repositories', 'List available module repositories');
        $this->optionJson();
    }

    public function execute(?bool $json = false): void
    {
        $format = $this->getOutputFormat('table');

        $repositories = ModuleRepositoryManager::getInstance()->repositories();

        $result_array = [];
        foreach ($repositories as $repository) {
            $result_array[] = [
                'ID' => $repository->getId(),
                'Name' => $repository->getDisplayName(),
            ];
        }

        $this->outputFormatted($result_array, $format);
    }
}
