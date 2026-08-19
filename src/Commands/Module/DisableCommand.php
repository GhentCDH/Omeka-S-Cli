<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Helper\ModuleDependencyOrder;

class DisableCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:disable', 'Disable module');
        $this->argumentModuleId(true);
        $this->option('-a --all', 'Disable all active modules', 'boolval', false);
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
            $module = $moduleApi->getModule($moduleId);
            if ($module->getState() === ModuleManager::STATE_NOT_ACTIVE) {
                $this->warn("Module '{$moduleId}' is already disabled.", true);
                return;
            }
            if ($this->isDryRun()) {
                $this->reportDryRun("Module '{$moduleId}' would be disabled.");
                return;
            }

            $moduleApi->disable($module);
            $this->ok("Module '{$moduleId}' disabled.", true);
        }

        if ($all) {
            $modulesToDisable = $this->collectModulesToDisable();

            if (!count($modulesToDisable)) {
                $this->info("No modules to disable.", true);
                return;
            }

            $success = $this->runBulkOperation(
                $modulesToDisable,
                fn($id) => $moduleApi->disable($moduleApi->getModule($id)),
                'Disabling',
                'disabled'
            );

            if (!$success) {
                $this->error("Some modules could not be disabled due to errors.", true);
            }
        }
    }

    /**
     * Collect the modules a bulk disable would affect.
     *
     * @return string[] Active module ids, dependencies last
     */
    protected function collectModulesToDisable(): array
    {
        // only an active module can be disabled, dependencies after their dependents
        return ModuleDependencyOrder::reverseSort(
            $this->collectModuleIdsByState([ModuleManager::STATE_ACTIVE])
        );
    }
}
