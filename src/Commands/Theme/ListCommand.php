<?php
namespace OSC\Commands\Theme;

use OSC\Helper\Offline;

class ListCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:list', 'List downloaded themes');
        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();

        $this->option('--outdated',     'Show outdated themes',               null, false);
    }

    public function execute(?bool $json = false, ?bool $extended = false, ?bool $outdated = false): void
    {
        if ($outdated) {
            Offline::assertOnline('check for outdated themes');
        }
        $format = $this->getOutputFormat('table');

        $themes = $this->getOmekaInstance()->getThemeApi()->getThemes();

        $output = [];
        foreach ($themes as $theme) {
            $themeInfo = $this->formatThemeStatus($theme, $extended);
            if ($outdated && !$themeInfo['updateAvailable']) {
                continue;
            }

            $output[] = $themeInfo;
        }

        $this->noteOfflineLatestVersions();
        $this->outputFormatted($output, $format);
    }
}
