<?php

namespace OSC\Commands\Theme;

use Omeka\Site\Theme\Theme;
use OSC\Manager\Result;
use OSC\Repository\Theme\ThemeDetails;


trait FormattersTrait
{
    private function formatThemeStatus(Theme $theme, bool $extended = false): array {
        $searchResult = $this->getThemeRepositoryManager()->find($theme->getId());

        $latestVersion = $searchResult?->getItem()?->getLatestVersionNumber() ?? null;

        $version = $theme->getIni('version');

        $status = [
            'id' => $theme->getId(),
            'name' => $theme->getName(),
            'state' => $theme->getState(),
            'version' => null,
            'latestVersion' => $latestVersion,
            'updateAvailable' => null,
            'author' => null,
            'path' => null,
        ];
        if ( !$this->getOmekaInstance()->getThemeApi()->hasErrors($theme) ) {
            $status['version'] = $version;
            $status['author'] = $theme->getIni('author');
            $status['path'] = $theme->getPath();
            $status['updateAvailable'] = $latestVersion && $version ? ($version !== $latestVersion) : null;
        }
        if (!$extended) {
            unset($status['path']);
        }
        return $status;
    }

    /**
     * @param Result<ThemeDetails>[] $themeResults
     * @param bool|null $extended
     * @return array
     */
    private function formatThemeResults(array $themeResults, ?bool $extended = false): array {
        $output = [];
        foreach ($themeResults as $themeResult) {
            $result = [
                'id' =>  $themeResult->getItem()->getId(),
                'latestVersion' => $themeResult->getItem()->getLatestVersionNumber(),
                'omekaVersionConstraint' => $themeResult->getItem()->getLatestVersion()?->getOmekaVersionConstraint(),
            ];
            if ($extended) {
                $result = array_merge($result, [
                    'url' => $themeResult->getItem()->getLink(),
                    'owner' => $themeResult->getItem()->getOwner() ?? '',
                    'repository' => $themeResult->getRepository()->getDisplayName(),
                ]);
            }
            $output[] = $result;
        }
        return $output;
    }
}