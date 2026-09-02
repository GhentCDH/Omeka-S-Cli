<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Helper\ModuleDependencyOrder;

class EnableCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:enable', 'Enable module');
        $this->argumentModuleId(true);
        $this->option('-a --all', 'Enable all disabled modules', 'boolval', false);
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
            if ($module->getState() === ModuleManager::STATE_ACTIVE) {
                $this->warn("Module '{$moduleId}' is already enabled.", true);
                return;
            }
            if ($this->isDryRun()) {
                $this->reportDryRun("Module '{$moduleId}' would be enabled.");
                return;
            }

            $moduleApi->enable($module);
            $this->ok("Module '{$moduleId}' enabled.", true);
        }

        if ($all) {
            $modulesToEnable = $this->collectModulesToEnable();

            if (!count($modulesToEnable)) {
                $this->info("No modules to enable.", true);
                return;
            }

            $success = $this->runBulkOperation(
                $modulesToEnable,
                fn($id) => $moduleApi->enable($moduleApi->getModule($id)),
                'Enabling',
                'enabled'
            );

            if (!$success) {
                $this->error("Some modules could not be enabled due to errors.", true);
            }
        }
    }

    /**
     * Collect the modules a bulk enable would affect.
     *
     * @return string[] Disabled module ids, dependencies first
     */
    protected function collectModulesToEnable(): array
    {
        // only a disabled module can be enabled, dependencies before their dependents
        return ModuleDependencyOrder::sort(
            $this->collectModuleIdsByState([ModuleManager::STATE_NOT_ACTIVE])
        );
    }
}
