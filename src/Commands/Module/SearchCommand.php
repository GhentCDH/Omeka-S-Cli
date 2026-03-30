<?php
namespace OSC\Commands\Module;

use OSC\Manager\Module\Manager as ModuleRepositoryManager;

class SearchCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    protected bool $optionExtended = true;
    protected bool $optionJson = true;
    public function __construct()
    {
        parent::__construct('module:search', 'Search/list available modules');

        $this
            ->argument('[query]', 'Part of the module name or description')
            ->option('-r --repository [repositoryid]', 'Filter by repository', 'strval')
            ->option('--refresh', 'Refresh the repository data', 'boolval', false);


        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $json = false, ?bool $extended = false, ?string $repository = null): void
    {
        $format = $this->getOutputFormat('table');

        $manager = ModuleRepositoryManager::getInstance();

        // refresh repositories?
        if ($this->values()['refresh'] ?? false) {
            $this->info("Refreshing vocabulary repositories...");
            $manager->refreshRepositories();
        }

        if ($query) {
            $moduleResults = $manager->search($query, $repository);
        } else {
            $moduleResults = $manager->list($repository);
        }

        $moduleList = $this->formatModuleResults($moduleResults, $extended);
        if (!$moduleList) {
            $this->warn("No modules found for query '{$query}'", true);
        }
        $this->outputFormatted($moduleList, $format);
    }
}
