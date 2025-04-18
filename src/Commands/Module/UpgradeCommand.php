<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class UpgradeCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    protected bool $argumentModuleId = true;

    public function __construct()
    {
        parent::__construct('module:upgrade', 'Uninstall module');
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId): void
    {
        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
        if ($module->getState() !== ModuleManager::STATE_NEEDS_UPGRADE) {
            $this->warn("Module '{$moduleId}' does not need upgrade.", true);
            return;
        }
        $this->getOmekaInstance()->getModuleApi()->upgrade($module);
        $this->ok("Module '{$moduleId}' successfully upgraded.", true);
    }
}
