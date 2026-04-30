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
    /** @var array<string, callable> */
    private array $propertyRegistry;

    /** @var array<string, callable> */
    private array $valueRegistry;

    public function __construct(private readonly ResourcePool $resourcePool, private readonly ResourceClassResolver $resourceClassResolver)
    {
        $this->propertyRegistry = [
            ItemSetGenerator::ID       => fn($c) => new ItemSetGenerator($c, $this->resourcePool),
            ResourceClassGenerator::ID => fn($c) => new ResourceClassGenerator($c, $this->resourceClassResolver),
            BoolGenerator::ID          => fn($c) => new BoolGenerator($c),
        ];

        $this->valueRegistry = [
            LiteralValueGenerator::ID  => fn($c) => new LiteralValueGenerator($c),
            UriValueGenerator::ID      => fn($c) => new UriValueGenerator($c),
            ResourceValueGenerator::ID => fn($c) => new ResourceValueGenerator($c, $this->resourcePool),
        ];
    }

    public function create(string $field, array $config): GeneratorInterface
    {
        $id       = $config['generator'] ?? '';
        $registry = str_starts_with($field, 'o:') ? $this->propertyRegistry : $this->valueRegistry;

        if (!isset($registry[$id])) {
            $supported = implode(', ', array_keys($registry));
            throw new \InvalidArgumentException(
                "Unknown generator: '{$id}'. Supported: {$supported}."
            );
        }

        try {
            return ($registry[$id])($config);
        } catch (\Throwable $th) {
            throw new \InvalidArgumentException(
                "Error creating generator for property '$field': " . $th->getMessage(),
                $th->getCode(),
                $th
            );
        }
    }
}
