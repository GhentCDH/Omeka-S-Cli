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
        $this->argumentModuleId(true);
        $this->option('-a --all', 'Update all modules', null, false);
        $this->option('-u --upgrade', 'Upgrade module after download', 'boolval', false);
        $this->optionDryRun();
    }

    public function execute(?string $moduleId, ?bool $upgrade = false, ?bool $all = false): void
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
            $module = $this->requireModule($moduleUri->getId());

            if ($this->isDryRun()) {
                $planned = $upgrade
                    ? "Module '{$module->getId()}' would be updated and upgraded."
                    : "Module '{$module->getId()}' would be updated.";
                $this->reportDryRun($planned);
                return;
            }

            // download the module
            /** @var DownloadCommand $command */
            $command = $this->app()->commands()['module:download'] ?? null;
            $command && $command->execute($moduleId, force: true);

            // upgrade the module if requested
            if ($upgrade) {
                // the module files on disc have just been replaced, but this process still holds
                // the Module class of the previous version, so the upgrade needs a new process
                if ($this->runInNewProcess(['module:upgrade', $module->getId()]) !== 0) {
                    throw new \Exception("Module '{$module->getId()}' was updated but could not be upgraded.");
                }
            }
        }

        if ($all) {
            $modulesToUpdate = $this->collectModulesToUpdate();

            if (!count($modulesToUpdate)) {
                $this->info("No modules to update.", true);
                return;
            }

            // download modules
            $download = function ($moduleId) {
                /** @var DownloadCommand $command */
                $command = $this->app()->commands()['module:download'] ?? null;
                $command && $command->execute($moduleId, force: true);
            };

            $hasErrors = !$this->runBulkOperation($modulesToUpdate, $download, 'Updating', 'updated');

            if ($this->isDryRun()) {
                // runBulkOperation reported the modules; the upgrade would follow in a new process
                if ($upgrade) {
                    $this->info('They would then be upgraded.', true);
                }
                return;
            }

            // upgrade modules if requested
            if ($upgrade) {
                // the module files on disc have just been replaced, but this process still holds
                // the Module classes of the previous versions, so the upgrade needs a new process
                try {
                    if ($this->runInNewProcess(['module:upgrade', '--all']) !== 0) {
                        $hasErrors = true;
                    }
                } catch (WarningException $e) {
                    $this->warn($e->getMessage(), true);
                } catch (Throwable $e) {
                    $hasErrors = true;
                    $this->error($e->getMessage(), true);
                }
            }

            if ($hasErrors) {
                throw new \Exception("Some modules could not be updated. Please check the error messages above.");
            }

            $this->info("All modules updated.", true);
        }
    }

    /**
     * Collect the modules a bulk update would affect.
     *
     * Unlike the other bulk commands this does not select on a module state: a module can only be
     * updated when the repository offers a newer version, which formatModuleStatus() resolves per
     * module through the repository manager. That is a network lookup, which is why the other
     * commands filter on state instead.
     *
     * @return string[] Module ids with a newer version available
     */
    protected function collectModulesToUpdate(): array
    {
        $moduleIds = [];
        foreach ($this->getOmekaInstance()->getModuleApi()->getModules() as $module) {
            $moduleInfo = $this->formatModuleStatus($module);
            if (!$moduleInfo['updateAvailable']) {
                continue;
            }
            $moduleIds[] = $moduleInfo['id'];
        }

        return $moduleIds;
    }
}
