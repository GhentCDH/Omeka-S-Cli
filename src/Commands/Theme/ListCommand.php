<?php
namespace OSC\Commands\Theme;

class ListCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:list', 'List downloaded themes');
        $this->optionJson();
        $this->optionExtended();
    }

    public function execute(?bool $json = false, ?bool $extended = false): void
    {
        $format = $this->getOutputFormat('table');

        $themes = $this->getOmekaInstance()->getThemeApi()->getThemes();

        $output = [];
        foreach ($themes as $theme) {
            $output[] = $this->formatThemeStatus($theme, $extended);
        }

        $this->outputFormatted($output, $format);
    }
}
