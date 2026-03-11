<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use OSC\Exceptions\WarningException;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUriType;
use Throwable;

class UpdateCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:update', 'Update module');
        $this->argument('[module-id]', 'Module name or ID to update', null);
        $this->option('-a --all', 'Update all modules', null, false);
        $this->option('-u --upgrade', 'Upgrade module after download', 'boolval', false);
    }

    public function execute(?string $moduleId, ?bool $upgrade, ?bool $all): void
    {
        if ($moduleId && $all) {
            throw new InvalidArgumentException("You cannot specify both a module ID and the --all option.");
        }

        if ($moduleId) {
            // parse module id to check format
            $moduleUri = ResourceUriParser::parse($moduleId);
            if ($moduleUri->getType() !== ResourceUriType::IdVersion) {
                throw new InvalidArgumentException("The module-id argument must be in the format 'module-id' or 'module-id:version'.");
            }

            // check if module exists (result is not used)
            $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleUri->getId());

            // download the module
            /** @var DownloadCommand $command */
            $command = $this->app()->commands()['module:download'] ?? null;
            $command && $command->execute($moduleId, force: true);

            // upgrade the module if requested
            if ($upgrade) {
                // flush the module manager to update the module state after download
                $this->getOmekaInstance()->getModuleApi()->reload();

                /** @var UpgradeCommand $command */
                $command = $this->app()->commands()['module:upgrade'] ?? null;
                $command && $command->execute($module->getId());
            }
        }

        if ($all) {
            $modulesToInstall = [];
            $modules = $this->getOmekaInstance()->getModuleApi()->getModules();
            foreach ($modules as $module) {
                $moduleInfo = $this->formatModuleStatus($module);
                if (!$moduleInfo['updateAvailable']) {
                    continue;
                }
                $modulesToInstall[] = $moduleInfo['id'];
            }

            if (!count($modulesToInstall)) {
                $this->info("No modules to update.", true);
                return;
            }

            // download modules
            $hasErrors = false;
            foreach ($modulesToInstall as $moduleId) {
                try {
                    $this->info("Updating module: $moduleId", true);

                    /** @var DownloadCommand $command */
                    $command = $this->app()->commands()['module:download'] ?? null;
                    $command && $command->execute($moduleId, force: true);
                } catch (WarningException $e) {
                    $this->io()->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $hasErrors = true;
                    $this->io()->error($e->getMessage(), true);
                }
            }

            // upgrade modules if requested
            if ($upgrade) {
                // flush the module manager to update the module state after download
                $this->getOmekaInstance()->getModuleApi()->reload();

                try {
                    /** @var UpgradeCommand $command */
                    $command = $this->app()->commands()['module:upgrade'] ?? null;
                    $command && $command->execute(moduleId: null, all: true);
                } catch (WarningException $e) {
                    $this->io()->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $hasErrors = true;
                    $this->io()->error($e->getMessage(), true);
                }
            }

            if ($hasErrors) {
                throw new \Exception("Some modules could not be updated. Please check the error messages above.");
            }

            $this->info("All modules updated successfully.", true);
        }
    }
}
