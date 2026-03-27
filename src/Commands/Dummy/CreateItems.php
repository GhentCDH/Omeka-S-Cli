<?php

namespace OSC\Commands\Dummy;

use OSC\Commands\Dummy\Config\ItemConfig;

class CreateItems extends AbstractDummyCommand
{
    public function __construct()
    {
        parent::__construct('dummy:create-items', 'Add dummy items');
        $this->argument('<total>', 'Number of items to create', 1);
        $this->option('--config', 'Path to JSON config file for item generation');
    }

    public function execute(int $total, ?string $config = null): void
    {
        $api        = $this->getOmekaInstance()->getApi();
        $total      = max(1, $total);
        $itemConfig = match (true) {
            $config !== null => ItemConfig::fromFile($config),
            default          => ItemConfig::fromDefaultConfig(),
        };

        $this->executeWithConfig($api, $total, $itemConfig, 'items');
    }
}
