<?php
namespace OSC\Commands\Theme;

class StatusCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:status', 'Get theme status');
        $this->optionJson();
        $this->optionExtended();
        $this->argumentThemeId();
    }

    public function execute(?string $themeId, ?bool $json = false, ?bool $extended = false): void
    {
        $format = $this->getOutputFormat('table');

        $theme = $this->getOmekaInstance()->getThemeApi()->getTheme($themeId);

        $this->outputFormatted([$this->formatThemeStatus($theme, $extended)], $format);
    }
}
