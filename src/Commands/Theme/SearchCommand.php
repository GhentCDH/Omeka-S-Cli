<?php
namespace OSC\Commands\Theme;

use OSC\Repository\Theme\OmekaDotOrg;

class SearchCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:search', 'Search/list available modules');

        $this->argument('[query]', 'Part of the theme name');
        $this->optionJson();
    }

    public function execute(?string $query, ?bool $json = false): void
    {
        $format = $this->getOutputFormat('table');
        $query = $query ? strtolower($query) : null;

        $themeRepo = new OmekaDotOrg();
        if ($query) {
            $themes = $themeRepo->search($query);
        } else {
            $themes = $themeRepo->list();
        }
        $output = [];
        foreach ($themes as $theme) {
            $output[] = [
                'id' =>  $theme->getId(),
                'latestVersion' => $theme->getLatestVersion(),
                'owner' => $theme->getOwner(),
            ];
        }
        $this->outputFormatted($output, $format);
    }
}
