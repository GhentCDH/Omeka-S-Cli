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
    }

    public function execute(?bool $json = false, ?bool $extended = false): void
    {
        $format = $this->getOutputFormat('table');

        $modules = $this->getOmekaInstance()->getModuleApi()->getModules();

        $output = [];
        foreach ($modules as $module) {
            $output[] = $this->formatModuleStatus($module, $extended);
        }

        $this->outputFormatted($output, $format);
    }
}
