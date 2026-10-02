<?php
namespace OSC\Commands\Module;

use OSC\Helper\VersionCompatibility;
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
            ->option('--refresh', 'Refresh the repository data', 'boolval', false)
            ->option('--include-incompatible', 'Include modules incompatible with the current Omeka S version', 'boolval', false);

        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $json = false, ?bool $extended = false, ?string $repository = null, ?bool $unregistered = false, ?bool $includeIncompatible = false): void
    {
        $format = $this->getOutputFormat('table');

        $manager = ModuleRepositoryManager::getInstance();

        if ($this->values()['refresh'] ?? false) {
            $this->info("Refreshing module repositories...", true);
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

        // when run inside an Omeka S instance, show only modules compatible with its version;
        // outside an instance there is nothing to constrain against, so list everything
        if (!$includeIncompatible && $this->hasOmekaInstance()) {
            $omekaVersion = $this->getOmekaVersion();
            $this->debug("Filtering modules compatible with Omeka S {$omekaVersion}", true);
            $moduleResults = array_filter($moduleResults, function($moduleResult) use ($omekaVersion) {
                return (bool) VersionCompatibility::getLatestCompatible(
                    $moduleResult->getItem()->getVersions(),
                    $omekaVersion
                );
            });
        }

        $moduleList = $this->formatModuleResults($moduleResults, $extended);
        if (!$moduleList) {
            $message = ($unregistered ? "No unregistered modules found" : "No modules found") . ($query ? " for query '{$query}'" : "");
            $this->warn($message, true);
        }
        $this->outputFormatted($moduleList, $format);
    }
}
