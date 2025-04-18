<?php

namespace OSC\Commands\Theme;

use Omeka\Site\Theme\Theme;


trait FormattersTrait
{
    private function formatThemeStatus(Theme $theme, bool $extended = false): array {
        $api_theme = $this->webApi->getTheme($theme->getId());

        $status = [
            'id' => $theme->getId(),
            'name' => $theme->getName(),
            'state' => $theme->getState(),
            'version' => null,
            'author' => null,
            'path' => null,
            'upgradeAvailable' => null,
            'isConfigurable' => null,
            'isConfigurableResourcePageBlocks' => null,
        ];
        if ( !$this->getOmekaInstance()->getThemeApi()->hasErrors($theme) ) {
            $status['version'] = $theme->getIni()['version'];
            $status['author'] = $theme->getIni()['author'];
            $status['isConfigurable'] = $theme->isConfigurable();
            $status['isConfigurableResourcePageBlocks'] = $theme->isConfigurableResourcePageBlocks();
            $status['path'] = $theme->getPath();
            $status['upgradeAvailable'] = isset($api_theme['latest_version']) ? ($theme->getIni()['version']!==$api_theme['latest_version'] ? $api_theme['latest_version']: 'up to date') : 'unknown';
        }
        if (!$extended) {
            unset($status['path']);
            unset($status['isConfigurable']);
            unset($status['isConfigurableResourcePageBlocks']);
        }
        return $status;
    }
}