<?php

namespace OSC\Commands\Dummy\Generator;

use OSC\Commands\Dummy\Generator\Property\BoolGenerator;
use OSC\Commands\Dummy\Generator\Property\ItemSetGenerator;
use OSC\Commands\Dummy\Generator\Property\ResourceClassGenerator;
use OSC\Commands\Dummy\Generator\ResourceValue\LiteralValueGenerator;
use OSC\Commands\Dummy\Generator\ResourceValue\ResourceValueGenerator;
use OSC\Commands\Dummy\Generator\ResourceValue\UriValueGenerator;
use OSC\Commands\Dummy\Resource\ResourceClassResolver;
use OSC\Commands\Dummy\Resource\ResourcePool;

class GeneratorFactory
{
    public function __construct(private ResourcePool $resourcePool, private ResourceClassResolver $resourceClassResolver)
    {
    }

    public function create(string $field, array $config): GeneratorInterface
    {
        if (str_starts_with($field, 'o:')) {
            return match ($config['generator']) {
                'item_set' => new ItemSetGenerator($config, $this->resourcePool),
                'resource_class' => new ResourceClassGenerator($config, $this->resourceClassResolver),
                'boolean' => new BoolGenerator($config),
                default      => throw new \InvalidArgumentException(
                    "Unknown generator: '{$config['generator']}'. Supported: item_set, resource_class, bool."
                ),
            };
        }

        return match ($config['generator'] ?? '') {
            'literal'  => new LiteralValueGenerator($config),
            'uri'      => new UriValueGenerator($config),
            'resource' => new ResourceValueGenerator($config, $this->resourcePool),
            default    => throw new \InvalidArgumentException(
                "Unknown generator: '{$config['generator']}'. Supported: literal, uri, resource."
            ),
        };
    }
}
