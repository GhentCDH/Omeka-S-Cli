<?php
namespace OSC\Commands\Config;

use Omeka\Module\Manager as ModuleManager;
use OSC\Commands\AbstractCommand;

class ModulesCommand extends AbstractCommand
{

    public function __construct()
    {
        parent::__construct('config:modules', 'Export list of modules');
    }

    public function defaults(): self {
        parent::defaults();
        $this->option('-i --installed', 'Export installed modules only', 'boolval', false);
        $this->optionEnv();
        $this->optionJson();

        return $this;
    }

    public function execute(?bool $installed = false): void
    {
        $format = $this->getOutputFormat('table');

        $moduleApi = $this->getOmekaInstance()->getModuleApi();

        $modules = $moduleApi->getModules();

        $output = [];
        foreach ($modules as $module) {
            if ($moduleApi->hasErrors($module)) {
                continue;
            }
            if ($installed && !in_array($module->getState(), [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE], true)) {
                continue;
            }
            $output[] = ["id" => $module->getId(), "version" => $module->getDb()['version'] ?? $module->getIni()['version'], "state" => $module->getState()];
        }

        switch ($format) {
            case 'env':
                $output = implode(' ', array_map(function ($item) { return "{$item['id']}:{$item['version']}"; }, $output));
                $this->io()->writer()->write($output,true);
                break;
            default:
                $this->outputFormatted($output, $format);
        }
    }
}
