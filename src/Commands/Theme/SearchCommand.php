<?php
namespace OSC\Commands\Theme;

use OSC\Helper\VersionCompatibility;

class SearchCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:search', 'Search/list available themes');
        $this->argument('[query]', 'Part of the theme name or description');
        $this->option('--refresh', 'Refresh the repository data', 'boolval', false);
        $this->option('--include-incompatible', 'Include themes incompatible with the current Omeka S version', 'boolval', false);

        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $extended = false, ?bool $includeIncompatible = false): void
    {
        $format = $this->getOutputFormat('table');
        $query = $query ? strtolower($query) : null;

        $manager = $this->getThemeRepositoryManager();

        // refresh repositories?
        if ($this->values()['refresh'] ?? false) {
            $this->info("Refreshing theme repositories...", true);
            $manager->refreshRepositories();
        }

        $themeResults = $query ? $manager->search($query) : $manager->list();

        // when run inside an Omeka S instance, show only themes compatible with its version;
        // outside an instance there is nothing to constrain against, so list everything
        if (!$includeIncompatible && $this->hasOmekaInstance()) {
            $omekaVersion = $this->getOmekaVersion();
            $this->debug("Filtering themes compatible with Omeka S {$omekaVersion}", true);
            $themeResults = array_filter($themeResults, function($themeResult) use ($omekaVersion) {
                return (bool) VersionCompatibility::getLatestCompatible(
                    $themeResult->getItem()->getVersions(),
                    $omekaVersion
                );
            });
        }

        $themeList = $this->formatThemeResults($themeResults, $extended);
        if (!$themeList) {
            $message = "No themes found" . ($query ? " for query '{$query}'" : '');
            $this->warn($message, true);
        }

        $this->outputFormatted($themeList, $format);
    }
}
