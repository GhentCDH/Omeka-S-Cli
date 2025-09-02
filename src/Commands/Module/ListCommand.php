<?php
namespace OSC\Commands\Module;

class ListCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:list', 'List downloaded modules');
        $this->optionJson();
        $this->optionExtended();
        $this->option('-o --outdated', 'Show outdated modules', null, false);
    }

    public function execute(?bool $json = false, ?bool $extended = false, ?bool $outdated = false): void
    {
        $format = $this->getOutputFormat('table');

        $modules = $this->getOmekaInstance()->getModuleApi()->getModules();

        $output = [];
        foreach ($modules as $module) {
            $moduleInfo = $this->formatModuleStatus($module, $extended);
            if ($outdated && !$moduleInfo['updateAvailable']) {
                continue;
            }
            if ($format === 'table') {
                $moduleInfo['updateAvailable'] = $moduleInfo['updateAvailable'] ? 'yes' : $moduleInfo['updateAvailable'];
            }
            $output[] = $moduleInfo;
        }

        $this->outputFormatted($output, $format);
    }
}
