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
        $this->option('-f --force', 'Force module uninstall', 'boolval', false);
        $this->option('--not-installed', 'Delete all uninstalled modules', 'boolval', false);
        $this->optionDryRun();
    }

    public function execute(?string $moduleId, ?bool $force = false, ?bool $notInstalled = false): void
    {
        if(!$moduleId && !$notInstalled) {
            throw new InvalidArgumentException("You must specify a module ID or the --not-installed option.");
        }

        if ($moduleId && $notInstalled) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --not-installed option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $moduleApi->getModule($moduleId);

            $installed = in_array(
                $module->getState(),
                [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE],
                true
            );

            if ($installed && !$force) {
                throw new Exception("The module is currently installed. Use the --force flag to uninstall the module.");
            }

            if ($this->isDryRun()) {
                $planned = $installed
                    ? "Module '{$moduleId}' would be uninstalled and deleted."
                    : "Module '{$moduleId}' would be deleted.";
                $this->reportDryRun($planned);
                return;
            }

            if ($installed) {
                // Uninstall the module
                $moduleApi->uninstall($module);
                $this->ok("Module '{$moduleId}' uninstalled.", true);
            }

            $moduleApi->delete($module);
            $this->ok("Module '{$moduleId}' deleted.", true);
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
