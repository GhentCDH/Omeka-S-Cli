<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Helper\ModuleDependencyOrder;

class UpgradeCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:upgrade', 'Upgrade module');
        $this->argumentModuleId(true);
        $this->option('-a --all', 'Upgrade all modules', null, false);
        $this->optionDryRun();
    }

    public function execute(?string $moduleId, bool $all = false): void
    {
        if(!$moduleId && !$all) {
            throw new InvalidArgumentException("You must specify a module ID or the --all option.");
        }

        if ($moduleId && $all) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --all option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        // Upgrade a specific module
        if ($moduleId) {
            $module = $moduleApi->getModule($moduleId);
            if ($module->getState() !== ModuleManager::STATE_NEEDS_UPGRADE) {
                $this->warn("Module '{$moduleId}' does not need upgrade.", true);
                return;
            }
            if ($this->isDryRun()) {
                $this->reportDryRun("Module '{$moduleId}' would be upgraded.");
                return;
            }

            $moduleApi->upgrade($module);
            $this->ok("Module '{$moduleId}' upgraded.", true);
        }

        // Upgrade all modules that need upgrade
        if ($all) {
            $modulesToUpgrade = $this->collectModulesToUpgrade();

            if (!count($modulesToUpgrade)) {
                $this->info("No modules to upgrade.", true);
                return;
            }

            $upgrade = function ($moduleId) use ($moduleApi) {
                $moduleApi->upgrade($moduleApi->getModule($moduleId));
                // reload module manager
                $moduleApi->reload();
            };

            $success = $this->runBulkOperation($modulesToUpgrade, $upgrade, 'Upgrading', 'upgraded');

            if (!$success) {
                $this->error("Some modules could not be upgraded due to errors.", true);
            }
        }
    }

    /**
     * Collect the modules a bulk upgrade would affect.
     *
     * @return string[] Module ids awaiting an upgrade, dependencies first
     */
    protected function collectModulesToUpgrade(): array
    {
        // dependencies are upgraded before the modules that rely on them
        return ModuleDependencyOrder::sort(
            $this->collectModuleIdsByState([ModuleManager::STATE_NEEDS_UPGRADE])
        );
    }
}
