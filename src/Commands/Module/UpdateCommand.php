<?php
namespace OSC\Commands\Module;

use InvalidArgumentException;
use OSC\Exceptions\WarningException;
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
            $argument = $moduleId;
            ['id' => $moduleId, 'version' => $moduleVersion] = $this->parseModuleVersionString($moduleId);
            $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);

            $command = new DownloadCommand();
            $command->bind($this->app());
            $command->execute($argument, true);
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

    private function parseModuleVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
    }
}
