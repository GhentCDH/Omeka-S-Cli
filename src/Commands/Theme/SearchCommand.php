<?php
namespace OSC\Commands\Theme;

class SearchCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:search', 'Search/list available themes');
        $this->argument('[query]', 'Part of the theme name or description');
        $this->option('--refresh', 'Refresh the repository data', 'boolval', false);

        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $extended = false): void
    {
        $format = $this->getOutputFormat('table');
        $query = $query ? strtolower($query) : null;

        $manager = $this->getThemeRepositoryManager();

        // refresh repositories?
        if ($this->values()['refresh'] ?? false) {
            $this->info("Refreshing vocabulary repositories...");
            $manager->refreshRepositories();
        }

        $themeResults = $query ? $manager->search($query) : $manager->list();

        $themeList = $this->formatThemeResults($themeResults, $extended);
        if (!$themeList) {
            $message = "No themes found" . ($query ? " for query '{$query}'" : '');
            $this->warn($message, true);
        }

        $this->outputFormatted($themeList, $format);
    }
}
