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
        $api_module = $this->getModuleRepositoryManager()->find($module->getId());

        $status = [
            'id' => $module->getId(),
            'name' => $module->getName(),
            'state' => $module->getState(),
            'version' => null,
            'upgradeAvailable' => null,
            'path' => null,
            'isConfigurable' => null,
        ];
        if ( !$this->getOmekaInstance()->getModuleApi()->hasErrors($module) ) {
            if ( $module->getState() === ModuleManager::STATE_NOT_INSTALLED ) {
                $status['version'] = $module->getIni()['version'];
            } else {
                $status['version'] = ($module->getDb()['version']==$module->getIni()['version']||!$module->getDb()['version'])?$module->getIni()['version']:($module->getIni()['version'].' ('.$module->getDb()['version'].' in database)')??'';
            }
            $latestVersion = $api_module?->getItem()?->getLatestVersionNumber();
            $status['upgradeAvailable'] = $latestVersion ? ($module->getIni()['version']!==$latestVersion ? $latestVersion: 'up to date') : 'unknown';
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
                'ID' =>  $moduleResult->getItem()->getDirname(),
                'Latest version' => $moduleResult->getItem()->getLatestVersionNumber(),
            ];
            if ($extended) {
                $result = array_merge($result, [
                    'Url' => $moduleResult->getItem()->getLink(),
                    'Description' => $moduleResult->getItem()->getDescription() ?? '',
                    'Owner' => $moduleResult->getItem()->getOwner() ?? '',
                    'Repository' => $moduleResult->getRepository()->getDisplayName(),
                ]);
            }
            $moduleList[] = $result;
        }
        return $moduleList;
    }
}