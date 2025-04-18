<?php
namespace OSC\Commands\Module;

use Omeka\Module\Manager as ModuleManager;

class StatusCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:status', 'Get module status');
        $this->optionJson();
        $this->optionExtended();
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId, ?bool $json = false, ?bool $extended = false): void
    {
        $format = $this->getOutputFormat('table');


        $module = $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);

        $this->outputFormatted([$this->formatModuleStatus($module, $extended)], $format);

        $this->ok("Module '{$moduleId}' successfully enabled.", true);
    }
}
