<?php
namespace OSC\Commands\Theme;

class ListCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:list', 'List downloaded themes');
        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();

        $this->option('--outdated',     'Show outdated modules',               null, false);
    }

    public function execute(?bool $json = false, ?bool $extended = false, ?bool $outdated = false): void
    {
        $format = $this->getOutputFormat('table');

        $themes = $this->getOmekaInstance()->getThemeApi()->getThemes();

        $output = [];
        foreach ($themes as $theme) {
            $themeInfo = $this->formatThemeStatus($theme, $extended);
            if ($outdated && !$themeInfo['updateAvailable']) {
                continue;
            }

            $output[] = $this->formatThemeStatus($theme, $extended);
        }

        $this->outputFormatted($output, $format);
    }
}
