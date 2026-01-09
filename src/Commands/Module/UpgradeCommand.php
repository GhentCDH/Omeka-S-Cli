<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use OSC\Exceptions\WarningException;
use Throwable;
use Omeka\Module\Manager as ModuleManager;

class UpgradeCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    protected bool $argumentModuleId = true;

    public function __construct()
    {
        parent::__construct('module:upgrade', 'Upgrade module');
        $this->argument('[module-id]', 'Module name or ID to update', null);
        $this->option('-a --all', 'Update all modules', null, false);
    }

    public function execute(?string $moduleId, ?bool $all): void
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
            $moduleApi->upgrade($module);
            $this->ok("Module '{$moduleId}' successfully upgraded.", true);
        }

        // Upgrade all modules that need upgrade
        if ($all) {
            $modulesToUpgrade = [];
            $modules = $moduleApi->getModules();
            foreach ($modules as $module) {
                if ($module->getState() !== ModuleManager::STATE_NEEDS_UPGRADE) {
                    continue;
                }
                $modulesToUpgrade[] = $module->getId();
            }

            if (!count($modulesToUpgrade)) {
                $this->info("No modules to upgrade.", true);
                return;
            }

            $errors = false;
            foreach ($modulesToUpgrade as $moduleId) {
                try {
                    $this->info("Upgrading module: $moduleId", true);

                    $module = $moduleApi->getModule($moduleId);
                    $moduleApi->upgrade($module);

                    $this->ok("Module '{$moduleId}' successfully upgraded.", true);
                } catch (WarningException $e) {
                    $this->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $this->error($e->getMessage(), true);
                    $errors = true;
                }
            }

            // Optionally, you could throw an exception or return a specific status if there were errors.
            if ($errors) {
                $this->error("Some modules could not be upgraded due to errors.", true);
            }
        }
    }
}
