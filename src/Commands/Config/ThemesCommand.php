<?php
namespace OSC\Commands\Config;

use OSC\Commands\AbstractCommand;

class ThemesCommand extends AbstractCommand
{

    public function __construct()
    {
        parent::__construct('config:themes', 'Export list of themes');
    }

    public function defaults(): self {
        parent::defaults();
        $this->option('-a --active', 'Export active themes only', 'boolval', false);
        $this->optionEnv();
        $this->optionJson();

        return $this;
    }

    public function execute(?bool $active = false): void
    {
        $format = $this->getOutputFormat('table');

        $themeApi = $this->getOmekaInstance()->getThemeApi();

        $themes = $themeApi->getThemes();

        $output = [];
        foreach ($themes as $theme) {
            if ($themeApi->hasErrors($theme)) {
                continue;
            }
            $themeActive = $themeApi->isActiveOnSite($theme);
            if ($active && !$themeActive) {
                continue;
            }
            $output[] = ["id" => $theme->getId(), "version" => $theme->getIni()['version'], "active" => $themeActive];
        }

        switch ($format) {
            case 'env':
                $output = implode(' ', array_map(function ($item) { return "{$item['id']}:{$item['version']}"; }, $output));
                $this->io()->writer()->write($output,true);
                break;
            default:
                $this->outputFormatted($output, $format);
        }
    }
}
