<?php

namespace OSC\Commands\Module;

use Omeka\Module\Module;
use Omeka\Module\Manager as ModuleManager;
use OSC\Manager\Module\ModuleResult;
use OSC\Manager\Result;
use OSC\Repository\Module\ModuleDetails;


trait FormattersTrait
{
    private function formatModuleStatus(Module $module, bool $extended = false): array {
        $searchResult = $this->getModuleRepositoryManager()->find($module->getId());
        $omekaInstance = $this->getOmekaInstance();

        $latestVersion = $searchResult?->getItem()?->getLatestVersionNumber() ?? null;

        $status = [
            'id' => $module->getId(),
            'name' => $module->getName(),
            'state' => $module->getState(),
            'version' => null,
            'installedVersion' => null,
            'latestVersion' => $latestVersion,
            'updateAvailable' => null,
            'path' => null,
            'isConfigurable' => null,
        ];
        if ( !$omekaInstance->getModuleApi()->hasErrors($module) ) {
            $version = $module->getIni()['version'];
            $status['version'] = $version;
            $status['installedVersion'] = $module->getDb()['version'];
            $status['updateAvailable'] = $latestVersion ? ($version !== $latestVersion) : null;
            $status['path'] = $module->getModuleFilePath();
            $status['isConfigurable'] = $module->isConfigurable();
        }

        if (!$extended) {
            unset($status['path']);
            unset($status['isConfigurable']);
        }
        return $status;
    }

    /**
     * @param Result<ModuleDetails>[] $moduleResults
     * @param bool|null $extended
     * @return array
     */
    private function formatModuleResults(array $moduleResults, ?bool $extended = false): array {
        $moduleList = [];
        foreach ($moduleResults as $moduleResult) {
            $result = [
                'id' =>  $moduleResult->getItem()->getDirname(),
                'latestVersion' => $moduleResult->getItem()->getLatestVersionNumber(),
            ];
            if ($extended) {
                $result = array_merge($result, [
                    'url' => $moduleResult->getItem()->getLink(),
                    'description' => $moduleResult->getItem()->getDescription() ?? '',
                    'owner' => $moduleResult->getItem()->getOwner() ?? '',
                    'repository' => $moduleResult->getRepository()->getDisplayName(),
                ]);
            }
            $moduleList[] = $result;
        }
        return $moduleList;
    }
}