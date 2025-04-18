<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class UninstallCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:uninstall', 'Uninstall module');
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId): void
    {
        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
        if ($module->getState() === ModuleManager::STATE_NOT_INSTALLED) {
            $this->warn("Module '{$moduleId}' is already uninstalled.", true);
            return;
        }

        $this->getOmekaInstance()->getModuleApi()->uninstall($module);
        $this->ok("Module '{$moduleId}' successfully uninstalled.", true);
    }
}
