<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use Omeka\Module\Manager as ModuleManager;
use OSC\Exceptions\WarningException;
use Throwable;

class UninstallCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:uninstall', 'Uninstall module');
        $this->option('--not-active', 'Uninstall all inactive modules', 'boolval', false);
        $this->argumentModuleId(true);
    }

    public function execute(?string $moduleId, ?bool $notActive): void
    {
        if(!$moduleId && !$notActive) {
            throw new InvalidArgumentException("You must specify a module ID or the --not-active option.");
        }

        if ($moduleId && $notActive) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --not-active option.");
        }

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        if ($moduleId) {
            $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
            if ($module->getState() === ModuleManager::STATE_NOT_INSTALLED) {
                $this->warn("Module '{$moduleId}' is already uninstalled.", true);
                return;
            }

            $moduleApi->uninstall($module);
            $this->ok("Module '{$moduleId}' uninstalled.", true);
        }

        if ($notActive) {
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

                    $this->ok("Module '{$moduleId}' uninstalled.", true);
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
