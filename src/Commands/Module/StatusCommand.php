<?php
namespace OSC\Commands\Module;

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

        $moduleInfo = $this->formatModuleStatus($module, $extended);
        if ($format === 'table') {
            $moduleInfo['updateAvailable'] = $moduleInfo['updateAvailable'] ? 'yes' : $moduleInfo['updateAvailable'];
        }
        $this->outputFormatted([$moduleInfo], $format);
    }
}
