<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class DisableCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:disable', 'Disable module');
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId): void
    {
        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
        if ($module->getState() === ModuleManager::STATE_NOT_ACTIVE) {
            $this->warn("Module '{$moduleId}' is already disabled.", true);
            return;
        }
        $this->getOmekaInstance()->getModuleApi()->disable($module);
        $this->ok("Module '{$moduleId}' successfully disabled.", true);
    }
}
