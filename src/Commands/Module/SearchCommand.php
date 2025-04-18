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
            ->option('-r --repository [repositoryid]>', 'Filter by repository', 'strval');

        $this->optionJson();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $json = false, ?bool $extended = false, ?string $repository = null): void
    {
        $format = $this->getOutputFormat('table');

        if ($query) {
            $moduleResults = ModuleRepositoryManager::getInstance()->search($query, $repository);
        } else {
            $moduleResults = ModuleRepositoryManager::getInstance()->list($repository);
        }
        $moduleList = $this->formatModuleResults($moduleResults, $extended);
        if (!$moduleList) {
            $this->warn("No modules found for query '{$query}'", true);
        }
        $this->outputFormatted($moduleList, $format);
    }
}
