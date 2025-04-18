<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class InstallCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:install', 'Install module');
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId): void
    {
        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
        if (in_array($module->getState(), [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE], true)) {
            $this->warn("Module '{$moduleId}' is already installed.", true);
            return;
        }
        // todo: Check module dependencies

        // install
        $this->getOmekaInstance()->getModuleApi()->install($module);
        $this->ok("Module '{$moduleId}' successfully installed.", true);
    }
}
