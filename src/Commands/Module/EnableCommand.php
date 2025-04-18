<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class EnableCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:enable', 'Enable module');
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId): void
    {
        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
        if ($module->getState() === ModuleManager::STATE_ACTIVE) {
            $this->warn("Module '{$moduleId}' is already enabled.", true);
            return;
        }
        $this->getOmekaInstance()->getModuleApi()->enable($module);
        $this->ok("Module '{$moduleId}' successfully enabled.", true);
    }
}
