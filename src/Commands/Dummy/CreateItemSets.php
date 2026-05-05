<?php

namespace OSC\Commands\Dummy;

use OSC\Commands\Dummy\Config\ItemSetConfig;

class CreateItemSets extends AbstractDummyCommand
{
    public function __construct()
    {
        parent::__construct('dummy:create-item-sets', 'Create dummy item sets');
        $this->option('-n --number', 'Number of item sets to create', 'intval', 1);
        $this->option('-c --config', 'Path or URL to JSON config for item set generation');
    }

    public function execute(int $number, ?string $config = null): void
    {
        $api        = $this->getOmekaInstance()->getApi();
        $number      = max(1, $number);
        $itemConfig = match (true) {
            $config !== null => ItemSetConfig::fromSource($config),
            default          => ItemSetConfig::fromDefaultConfig(),
        };

        $this->executeWithConfig($api, $number, $itemConfig, 'item_sets');
    }
}
