<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class DeleteCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:delete', 'Delete module');
        $this->option('-f --force', 'Force module uninstall', 'boolval', false);
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId, ?bool $force): void
    {
        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);

        if($module->getState() === ModuleManager::STATE_ACTIVE || $module->getState() === ModuleManager::STATE_NOT_ACTIVE) {
            if (!$force) {
                throw new \Exception("The module is currently installed. Use the --force flag to uninstall the module.");
            }
            // Uninstall the module
            $this->getOmekaInstance()->getModuleApi()->uninstall($module);
            $this->ok("Module '{$moduleId}' successfully uninstalled.", true);
        }

        $this->getOmekaInstance()->getModuleApi()->delete($module);
        $this->ok("Module '{$moduleId}' successfully deleted.", true);
    }
}
