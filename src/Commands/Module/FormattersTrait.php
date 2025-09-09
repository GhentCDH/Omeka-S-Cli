<?php

namespace OSC\Commands\Module;

use Omeka\Module\Module;
use OSC\Manager\Result;
use OSC\Repository\Module\ModuleDetails;


trait FormattersTrait
{
    private function formatModuleStatus(Module $module, bool $extended = false): array {
        $searchResult = $this->getModuleRepositoryManager()->find($module->getId());

        $latestVersion = $searchResult?->getItem()?->getLatestVersionNumber() ?? null;

        $version = $module->getIni('version');

        $status = [
            'id' => $module->getId(),
            'name' => $module->getName(),
            'state' => $module->getState(),
            'version' => $version,
            'installedVersion' => $module->getDb('version'),
            'latestVersion' => $latestVersion,
            'updateAvailable' => $latestVersion && $version ? ($version !== $latestVersion) : null,
            'path' => $module->getModuleFilePath(),
            'isConfigurable' => $module->isConfigurable(),
        ];

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