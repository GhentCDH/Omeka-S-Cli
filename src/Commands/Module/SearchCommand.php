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
            ->option('--unregistered', 'Show only modules not registered in the omeka.org add-ons directory')
            ->option('--refresh', 'Refresh the repository data', 'boolval', false);

        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $json = false, ?bool $extended = false, ?string $repository = null, ?bool $unregistered = false): void
    {
        $format = $this->getOutputFormat('table');

        $manager = ModuleRepositoryManager::getInstance();

        if ($this->values()['refresh'] ?? false) {
            $this->info("Refreshing vocabulary repositories...");
            $manager->refreshRepositories();
        }

        if ($unregistered) {
            $moduleResults = $query
                ? $manager->searchExclusive($query, 'omeka.org')
                : $manager->listExclusive('omeka.org');
        } elseif ($query) {
            $moduleResults = $manager->search($query, $repository);
        } else {
            $moduleResults = $manager->list($repository);
        }

        $moduleList = $this->formatModuleResults($moduleResults, $extended);
        if (!$moduleList) {
            $message = ($unregistered ? "No unregistered modules found" : "No modules found") . ($query ? " for query '{$query}'" : "");
            $this->warn($message, true);
        }
        $this->outputFormatted($moduleList, $format);
    }
}
