<?php

namespace OSC\Commands\Dummy;

use OSC\Commands\Dummy\Config\ItemSetConfig;

class CreateItemSets extends AbstractDummyCommand
{
    public function __construct()
    {
        parent::__construct('dummy:create-item-sets', 'Add dummy item sets');
        $this->argument('<total>', 'Number of item sets to create', 'intval', 1);
        $this->option('--config', 'Path to JSON config file for item set generation');
    }

    public function execute(int $total, ?string $config = null): void
    {
        $api        = $this->getOmekaInstance()->getApi();
        $total      = max(1, $total);
        $itemConfig = match (true) {
            $config !== null => ItemSetConfig::fromFile($config),
            default          => ItemSetConfig::fromDefaultConfig(),
        };

        $this->executeWithConfig($api, $total, $itemConfig, 'item_sets');
    }
}
