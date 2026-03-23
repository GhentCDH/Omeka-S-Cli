<?php

namespace OSC\Commands\Dummy;

use OSC\Commands\AbstractCommand;
use OSC\Commands\Dummy\Config\ResourceConfig;
use OSC\Commands\Dummy\Generator\ResourceGenerator;
use OSC\Commands\Dummy\Resource\ResourceClassResolver;
use OSC\Commands\Dummy\Resource\ResourcePool;

abstract class AbstractDummyCommand extends AbstractCommand
{
    /**
     * Run the full generation loop for the given resource type ('items' or 'item_sets').
     */
    protected function executeWithConfig($api, int $total, ResourceConfig $config, string $resourceType): void
    {
        $pool      = new ResourcePool($api);
        $resolver  = new ResourceClassResolver($api);
        $generator = new ResourceGenerator($config, $pool, $resolver);

        $this->info('Start creation ...', true);
        for ($i = 1; $i <= $total; $i++) {
            $ret = $generator->generate();
            $api->create($resourceType, $ret, [], [
                'responseContent' => 'resource',
                'initialize'      => false,
                'finalize'        => false,
            ]);
            if ($i % 100 === 0) {
                $this->info("Created $i ...", true);
            }
        }
        $this->ok("Successfully created {$total} {$resourceType}.", true);
    }
}
