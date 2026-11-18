<?php
namespace OSC\Commands\Module;

use Exception;
use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;

class DeleteCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:delete', 'Delete module');
        $this->argumentModuleId(true);
        $this->option(
            '-f --force',
            'Uninstall an installed module, or remove the database registration of a module Omeka S cannot uninstall',
            'boolval',
            false
        );
        $this->option('--not-installed', 'Delete all uninstalled modules', 'boolval', false);
        $this->optionDryRun();
        $this->optionIgnoreNotFound('module');
    }

    public function execute(
        ?string $moduleId,
        ?bool $force = false,
        ?bool $notInstalled = false,
        ?bool $ignoreNotFound = false
    ): void {
        if(!$moduleId && !$notInstalled) {
            throw new InvalidArgumentException("You must specify a module ID or the --not-installed option.");
        }

        if ($moduleId && $notInstalled) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --not-installed option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $this->requireModule($moduleId, $ignoreNotFound);


            $state = $module->getState();
            $installed = in_array(
                $state,
                [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE],
                true
            );
            // registered in the database, but Omeka S can not uninstall it (files gone, needs an
            // upgrade, invalid for this Omeka S version, ...)
            $stranded = !$installed && $moduleApi->isRegistered($module);
            $hasFiles = $state !== ModuleManager::STATE_NOT_FOUND;

            if ($installed && !$force) {
                throw new Exception("The module is currently installed. Use the --force flag to uninstall the module.");
            }

            if ($stranded && !$force) {
                throw new Exception(
                    "Module '{$moduleId}' is registered in the database but Omeka S cannot uninstall it (state: {$state}). "
                    . "Use the --force flag to remove its registration; its own tables and settings are left in place."
                );
            }

            if ($this->isDryRun()) {
                if ($installed) {
                    $planned = "Module '{$moduleId}' would be uninstalled and deleted.";
                } elseif ($stranded) {
                    $planned = $hasFiles
                        ? "Module '{$moduleId}' would be removed from the database and deleted."
                        : "Module '{$moduleId}' would be removed from the database.";
                } else {
                    $planned = "Module '{$moduleId}' would be deleted.";
                }
                $this->reportDryRun($planned);
                return;
            }

            if ($installed) {
                // Uninstall the module
                $moduleApi->uninstall($module);
                $this->ok("Module '{$moduleId}' uninstalled.", true);
            }

            if ($stranded) {
                $moduleApi->unregister($module);
                $this->warn(
                    "Module '{$moduleId}' was removed from the database without running its uninstaller; "
                    . "its tables and settings are left in place.",
                    true
                );
                $this->ok("Module '{$moduleId}' removed from the database.", true);
            }

            if ($hasFiles) {
                $moduleApi->delete($module);
                $this->ok("Module '{$moduleId}' deleted.", true);
            }
        }

        if ($notInstalled) {
            $modulesToDelete = $this->collectModulesToDelete();

            if (!count($modulesToDelete)) {
                $this->info("No modules to delete.", true);
                return;
            }

            $delete = fn($moduleId) => $moduleApi->delete($moduleApi->getModule($moduleId));

            $success = $this->runBulkOperation($modulesToDelete, $delete, 'Deleting', 'deleted');

            if (!$success) {
                $this->error("Some modules could not be deleted due to errors.", true);
            }
        }
    }

    /**
     * Collect the modules a bulk delete would affect.
     *
     * Order does not matter here: the files removed belong to modules that are not installed,
     * so nothing depends on them at this point.
     *
     * @return string[] Module ids that are not installed
     */
    protected function collectModulesToDelete(): array
    {
        // only a module that is not installed can have its files removed
        return $this->collectModuleIdsByState([ModuleManager::STATE_NOT_INSTALLED]);
    }
}
