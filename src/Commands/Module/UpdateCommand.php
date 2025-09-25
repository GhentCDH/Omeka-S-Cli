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
    }

    public function execute(?string $moduleId, ?bool $all): void
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
            $command = new DownloadCommand();
            $command->bind($this->app());
            $command->execute($moduleId, true);
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

            foreach ($modulesToInstall as $moduleId) {
                try {
                    $this->info("Updating module: $moduleId", true);

                    $command = new DownloadCommand();
                    $command->bind($this->app());
                    $command->execute($moduleId, true);
                } catch (WarningException $e) {
                    $this->io()->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $this->io()->error($e->getMessage(), true);
                }
            }
        }
    }
}
