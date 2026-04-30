<?php

namespace OSC\Commands\Dummy\Generator\ResourceValue;

use Exception;
use OSC\Commands\Dummy\Generator\GeneratorInterface;
use OSC\Commands\Dummy\Resource\ResourcePool;

class ResourceValueGenerator implements ResourceValueGeneratorInterface
{
    public const ID = 'resource';

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
            $message = match($this->resourceType) {
                "items" => "No items found. You must add at least one item before you can create resource values.",
                "item_sets" => "No item sets found. You must add at least one item set before you can create resource values.",
                "any" => "No resources found. You must add at least one item or item set before you can create resource values.",
            };
            throw new \InvalidArgumentException(
                $message
            );
        }
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function generate(): array|null
    {
        $resourceIds = $this->values ?: $this->pool->get($this->resourceType);
        if (empty($resourceIds)) {
            return null;
        }

        $resourceId  = $resourceIds[array_rand($resourceIds)];

        return [
            'type'              => 'resource',
            'property_id'       => 'auto',
            'is_public'         => true,
            'value_resource_id' => $resourceId,
        ];
    }
}
