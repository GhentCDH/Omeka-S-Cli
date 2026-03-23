<?php

namespace OSC\Commands\Dummy\Generator\ResourceValue;

use Exception;
use OSC\Commands\Dummy\Generator\GeneratorInterface;
use OSC\Commands\Dummy\Resource\ResourcePool;

class ResourceValueGenerator implements ResourceValueGeneratorInterface
{
    private const VALID_RESOURCE_TYPES = ['items', 'item_sets', 'any'];

    private string $resourceType;
    private array $values = [];

    public function __construct(private array $config, private ResourcePool $pool)
    {
        $resourceType = $config['resourceType'] ?? 'any';
        $this->resourceType = $resourceType;
        if (!in_array($resourceType, self::VALID_RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown resourceType '{$resourceType}'. Supported types: " . implode(', ', self::VALID_RESOURCE_TYPES) . '.'
            );
        }

        if (isset($config['values'])) {
            if (empty($config['values']) || !is_array($config['values'])) {
                throw new \InvalidArgumentException(
                    "Resource generator 'values' must be a non-empty array."
                );
            }
            $pool->requireIds($resourceType, $config['values']);
            $this->values = $config['values'];
        } else {
            $pool->init($resourceType);
        }

        if (empty($pool->get($this->resourceType))) {
            throw new Exception(
                "No resources found for pool '{$this->resourceType}'."
            );
        }
    }

    public function getId(): string
    {
        return "resource";
    }

    public function generate(): array
    {
        $ids = $this->values ?: $this->pool->get($this->resourceType);
        $id  = $ids[array_rand($ids)];

        return [
            'type'              => 'resource',
            'property_id'       => 'auto',
            'is_public'         => true,
            'value_resource_id' => $id,
        ];
    }
}
