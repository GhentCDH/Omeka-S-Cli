<?php

namespace OSC\Commands\Module;

use Omeka\Module\Module;
use Omeka\Module\Manager as ModuleManager;


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

    private function formatModuleResults($moduleResults, ?bool $extended = false): array {
        $moduleList = [];
        foreach ($moduleResults as $moduleResult) {
            $result = [
                'ID' =>  $moduleResult->module->dirname,
                'Latest version' => $moduleResult->module->latestVersion,
            ];
            if ($extended) {
                $result = array_merge($result, [
                    'Url' => $moduleResult->module->link,
                    'Description' => $moduleResult->module->description ?? '',
                    'Owner' => $moduleResult->module->owner ?? '',
                    'Repository' => $moduleResult->repository->getDisplayName(),
                ]);
            }
            $moduleList[] = $result;
        }
        return $moduleList;
    }
}