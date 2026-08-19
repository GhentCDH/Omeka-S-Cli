<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Helper\ModuleDependencyOrder;

class UninstallCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:uninstall', 'Uninstall module');
        $this->option('-a --all', 'Uninstall all installed modules', 'boolval', false);
        $this->option('--not-active', 'Uninstall all inactive modules', 'boolval', false);
        $this->optionDryRun();
        $this->argumentModuleId(true);
    }

    public function execute(?string $moduleId, ?bool $all = false, ?bool $notActive = false): void
    {
        $selectors = count(array_filter([$moduleId, $all, $notActive]));

        if ($selectors === 0) {
            throw new InvalidArgumentException("You must specify a module ID, the --all option or the --not-active option.");
        }

        if ($selectors > 1) {
            throw new InvalidArgumentException("You can only specify one of a module ID, the --all option or the --not-active option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $moduleApi->getModule($moduleId);
            if ($module->getState() === ModuleManager::STATE_NOT_INSTALLED) {
                $this->warn("Module '{$moduleId}' is already uninstalled.", true);
                return;
            }

            if ($this->isDryRun()) {
                $this->reportDryRun("Module '{$moduleId}' would be uninstalled.");
                return;
            }

            $moduleApi->uninstall($module);
            $this->ok("Module '{$moduleId}' uninstalled.", true);
            return;
        }

        if ($all) {
            // a module that still needs an upgrade can not be uninstalled by Omeka S
            $skipped = $this->collectModulesNeedingUpgrade();
            if ($skipped) {
                $message = sprintf(
                    'Skipping %d module(s) that need an upgrade first: %s.',
                    count($skipped),
                    implode(', ', $skipped)
                );
                $this->warn($message, true);
            }
        }

        $modulesToUninstall = $this->collectModulesToUninstall((bool) $all, (bool) $notActive);

        if (!count($modulesToUninstall)) {
            $this->info("No modules to uninstall.", true);
            return;
        }

        $success = $this->runBulkOperation(
            $modulesToUninstall,
            fn($id) => $moduleApi->uninstall($moduleApi->getModule($id)),
            'Uninstalling',
            'uninstalled'
        );

        if (!$success) {
            $this->error("Some modules could not be uninstalled due to errors.", true);
        }
    }

    /**
     * Collect the modules a bulk uninstall would affect.
     *
     * Each selector names the states it acts on, so that neither option depends on the other
     * being unset. Without a selector nothing is selected; execute() reports that as an error.
     *
     * @param bool $all       Uninstall every installed module
     * @param bool $notActive Uninstall the inactive modules only
     *
     * @return string[] Module ids, dependencies last
     */
    protected function collectModulesToUninstall(bool $all, bool $notActive): array
    {
        // only an installed module, active or not, can be uninstalled
        if ($all) {
            $states = [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE];
        } elseif ($notActive) {
            $states = [ModuleManager::STATE_NOT_ACTIVE];
        } else {
            return [];
        }

        // dependencies are uninstalled after the modules that rely on them
        return ModuleDependencyOrder::reverseSort($this->collectModuleIdsByState($states));
    }

    /**
     * Collect the installed modules that an upgrade has to run on first.
     *
     * These can not be uninstalled by Omeka S yet, so a bulk uninstall reports them as skipped.
     *
     * @return string[] Module ids awaiting an upgrade
     */
    protected function collectModulesNeedingUpgrade(): array
    {
        return $this->collectModuleIdsByState([ModuleManager::STATE_NEEDS_UPGRADE]);
    }
}
