<?php
namespace OSC\Commands\Module;

use Exception;
use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Exceptions\WarningException;

class DeleteCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:delete', 'Delete module');
        $this->option('-f --force', 'Force module uninstall', 'boolval', false);
        $this->option('-u --uninstalled', 'Delete all uninstalled modules', 'boolval', false);
        $this->argumentModuleId(true);
    }

    public function execute(?string $moduleId, ?bool $force, ?bool $uninstalled): void
    {
        if(!$moduleId && !$uninstalled) {
            throw new InvalidArgumentException("You must specify a module ID or the --uninstalled option.");
        }

        if ($moduleId && $uninstalled) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --uninstalled option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $moduleApi->getModule($moduleId);

            if($module->getState() === ModuleManager::STATE_ACTIVE || $module->getState() === ModuleManager::STATE_NOT_ACTIVE) {
                if (!$force) {
                    throw new Exception("The module is currently installed. Use the --force flag to uninstall the module.");
                }
                // Uninstall the module
                $moduleApi->uninstall($module);
                $this->ok("Module '{$moduleId}' successfully uninstalled.", true);
            }

            $moduleApi->delete($module);
            $this->ok("Module '{$moduleId}' successfully deleted.", true);
        }

        if ($uninstalled) {
            $modulesToDelete = [];
            $modules = $moduleApi->getModules();
            foreach ($modules as $module) {
                if ($module->getState() !== ModuleManager::STATE_NOT_INSTALLED) {
                    continue;
                }
                $modulesToDelete[] = $module->getId();
            }

            if (!count($modulesToDelete)) {
                $this->info("No modules to delete.", true);
                return;
            }

            $errors = false;
            foreach ($modulesToDelete as $moduleId) {
                try {
                    $this->info("Delete module: $moduleId", true);

                    $module = $moduleApi->getModule($moduleId);
                    $moduleApi->delete($module);

                    $this->ok("Module '{$moduleId}' successfully deleted.", true);
                } catch (WarningException $e) {
                    $this->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $this->error($e->getMessage(), true);
                    $errors = true;
                }
            }

            if ($errors) {
                $this->error("Some modules could not be deleted due to errors.", true);
            }
        }
    }
}
