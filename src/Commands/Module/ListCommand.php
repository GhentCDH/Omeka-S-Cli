<?php
namespace OSC\Commands\Module;

class ListCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:list', 'List downloaded modules');
        $this->optionJson();
        $this->optionCSV();
        $this->optionExtended();
        $this->option('--outdated',     'Show outdated modules',               null, false);
        $this->option('--active',          'Show active modules',                 null, false);
        $this->option('--not-active',        'Show inactive (not active) modules',  null, false);
        $this->option('--not-installed',   'Show not installed modules',          null, false);
        $this->option('--needs-upgrade',   'Show modules that need upgrading',    null, false);
    }

    public function execute(?bool $extended = false, ?bool $outdated = false): void
    {
        $format = $this->getOutputFormat('table');

        $modules = $this->getOmekaInstance()->getModuleApi()->getModules();

        $values = $this->values();
        $stateFilters = array_filter([
            $values['active']       ? 'active'        : null,
            $values['notActive']     ? 'not_active'    : null,
            $values['notInstalled'] ? 'not_installed' : null,
            $values['needsUpgrade'] ? 'needs_upgrade' : null,
        ]);

        $output = [];
        foreach ($modules as $module) {
            $moduleInfo = $this->formatModuleStatus($module, $extended);
            if ($outdated && !$moduleInfo['updateAvailable']) {
                continue;
            }
            if (!empty($stateFilters) && !in_array($moduleInfo['state'], $stateFilters, true)) {
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
