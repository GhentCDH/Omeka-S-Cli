<?php

namespace OSC\Commands\Theme;

use Omeka\Site\Theme\Theme;
use OSC\Addon\AddonVersion;
use OSC\Helper\Offline;
use OSC\Manager\Result;
use OSC\Repository\Theme\ThemeDetails;


trait FormattersTrait
{
    private function formatThemeStatus(Theme $theme, bool $extended = false): array {
        // offline the repositories are not queried: the latest version stays empty
        $searchResult = Offline::isEnabled() ? null : $this->getThemeRepositoryManager()->find($theme->getId());

        $latestVersion = $searchResult?->getItem()?->getLatestVersionNumber() ?? null;

        $version = $theme->getIni('version');

        $status = [
            'id' => $theme->getId(),
            'name' => $theme->getName(),
            'state' => $theme->getState(),
            // also for a theme this Omeka S rejects (its ini is still read): an update often fixes that,
            // so theme:update --all must see it (null when there is no readable ini)
            'version' => $version,
            'latestVersion' => $latestVersion,
            // what theme:update would install: the latest release compatible with this Omeka S
            'updateAvailable' => $searchResult ? AddonVersion::updateAvailable($version, $searchResult->getItem()->getVersions(), $this->getOmekaVersion()) : null,
            'author' => null,
            'path' => null,
        ];
        if ( !$this->getOmekaInstance()->getThemeApi()->hasErrors($theme) ) {
            $status['author'] = $theme->getIni('author');
            $status['path'] = $theme->getPath();
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