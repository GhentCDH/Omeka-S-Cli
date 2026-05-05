<?php

namespace OSC\Commands\Dummy;

use OSC\Commands\Dummy\Config\ItemConfig;

class CreateItems extends AbstractDummyCommand
{
    public function __construct()
    {
        parent::__construct('dummy:create-items', 'Create dummy items');
        $this->option('-n --number', 'Number of items to create', 'intval', 1);
        $this->option('-c --config', 'Path or URL to JSON config for item generation');
    }

    public function execute(int $number, ?string $config = null): void
    {
        $api        = $this->getOmekaInstance()->getApi();
        $number      = max(1, $number);
        $itemConfig = match (true) {
            $config !== null => ItemConfig::fromSource($config),
            default          => ItemConfig::fromDefaultConfig(),
        };

        $this->executeWithConfig($api, $number, $itemConfig, 'items');
    }
}
