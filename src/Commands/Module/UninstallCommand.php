<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Exceptions\WarningException;

class UninstallCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:uninstall', 'Uninstall module');
        $this->option('--inactive', 'Delete all inactive modules', 'boolval', false);
        $this->argumentModuleId(true);
    }

    public function execute(?string $moduleId, ?bool $inactive): void
    {
        if(!$moduleId && !$inactive) {
            throw new InvalidArgumentException("You must specify a module ID or the --inactive option.");
        }

        if ($moduleId && $inactive) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --inactive option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
            if ($module->getState() === ModuleManager::STATE_NOT_INSTALLED) {
                $this->warn("Module '{$moduleId}' is already uninstalled.", true);
                return;
            }

            $this->getOmekaInstance()->getModuleApi()->uninstall($module);
            $this->ok("Module '{$moduleId}' successfully uninstalled.", true);
        }

        if ($inactive) {
            $modulesToUninstall = [];
            $modules = $moduleApi->getModules();
            foreach ($modules as $module) {
                if ($module->getState() !== ModuleManager::STATE_NOT_ACTIVE) {
                    continue;
                }
                $modulesToUninstall[] = $module->getId();
            }

            if (!count($modulesToUninstall)) {
                $this->info("No modules to uninstall.", true);
                return;
            }

            $errors = false;
            foreach ($modulesToUninstall as $moduleId) {
                try {
                    $this->info("Uninstall module: $moduleId", true);

                    $module = $moduleApi->getModule($moduleId);
                    $moduleApi->uninstall($module);

                    $this->ok("Module '{$moduleId}' successfully uninstalled.", true);
                } catch (WarningException $e) {
                    $this->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $this->error($e->getMessage(), true);
                    $errors = true;
                }
            }

            if ($errors) {
                $this->error("Some modules could not be uninstalled due to errors.", true);
            }
        }
    }
}
