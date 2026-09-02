<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Helper\ModuleDependencyOrder;

class InstallCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:install', 'Install module');
        $this->argumentModuleId(true);
        $this->option('-a --all', 'Install all downloaded modules that are not installed', 'boolval', false);
        $this->optionDryRun();
    }

    public function execute(?string $moduleId, ?bool $all = false): void
    {
        if (!$moduleId && !$all) {
            throw new InvalidArgumentException("You must specify a module ID or the --all option.");
        }

        if ($moduleId && $all) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --all option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $this->requireModule($moduleId);
            if (in_array($module->getState(), [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE], true)) {
                $this->warn("Module '{$moduleId}' is already installed.", true);
                return;
            }
            // todo: Check module dependencies
            if ($this->isDryRun()) {
                $this->reportDryRun("Module '{$moduleId}' would be installed.");
                return;
            }

            $moduleApi->install($module);
            $this->ok("Module '{$moduleId}' installed.", true);
        }

        if ($all) {
            $modulesToInstall = $this->collectModulesToInstall();

            if (!count($modulesToInstall)) {
                $this->info("No modules to install.", true);
                return;
            }

            $success = $this->runBulkOperation(
                $modulesToInstall,
                fn($id) => $moduleApi->install($moduleApi->getModule($id)),
                'Installing',
                'installed'
            );

            if (!$success) {
                $this->error("Some modules could not be installed due to errors.", true);
            }
        }
    }

    /**
     * Collect the modules a bulk install would affect.
     *
     * @return string[] Module ids that are downloaded but not installed, dependencies first
     */
    protected function collectModulesToInstall(): array
    {
        // only a module that is not installed can be installed, dependencies before their dependents
        return ModuleDependencyOrder::sort(
            $this->collectModuleIdsByState([ModuleManager::STATE_NOT_INSTALLED])
        );
    }
}
