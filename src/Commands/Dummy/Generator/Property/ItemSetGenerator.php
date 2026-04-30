<?php

namespace OSC\Commands\Dummy\Generator\Property;

use OSC\Commands\Dummy\Resource\ResourcePool;

class ItemSetGenerator implements PropertyGeneratorInterface
{
    public const ID = 'item_set';

    public function __construct(private array $config, private ResourcePool $pool)
    {
        if (isset($config['values'])) {
            if (empty($config['values']) || !is_array($config['values'])) {
                throw new \InvalidArgumentException(
                    "Item set generator 'values' must be a non-empty array."
                );
            }
            // check if item sets exist
            $pool->requireIds('item_sets', $config['values']);
        } else {
            $pool->init('item_sets');
        }

        $this->config['min'] = $config['min'] ?? 1;
        $this->config['max'] = $config['max'] ?? 1;

        if (!is_int($config['min'])) {
            throw new \InvalidArgumentException(
                "Item set generator requires an integer 'min'."
            );
        }


        if (!is_int($config['max'])) {
            throw new \InvalidArgumentException(
                "Item set generator requires an integer 'max' when 'min' is set."
            );
        }

        if ($this->config['min'] > $this->config['max']) {
            throw new \InvalidArgumentException(
                "Item set generator requires 'min' to be less than or equal to 'max'."
            );
        }

        // check if resource pool contains item sets
        if ($config['min'] > 0) {
            $availableCount = count($pool->get('item_sets'));
            if ($availableCount < $this->config['min']) {
                throw new \InvalidArgumentException(
                    "Item set generator requires at least {$this->config['min']} item sets based on configuration. Add item sets or update the 'min' property in the configuration."
                );
            }
        }
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function generate(): ?array
    {
        if (isset($this->config['values'])) {
            $pool = $this->config['values'];
            $ids  = isset($this->config['min'])
                    ? $this->pickRandom($pool, $this->config['min'], $this->config['max'])
                    : $pool;
        } else {
            $ids = $this->pickRandom($this->pool->get('item_sets'), $this->config['min'], $this->config['max']);
        }

        if (empty($ids)) {
            return null;
        }

        return $ids;
    }

    private function pickRandom(array $pool, int $min, int $max): array
    {
        if (empty($pool)) {
            return [];
        }

        $count = rand($min, min($max, count($pool)));
        $keys  = array_rand($pool, $count);

        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return array_map(fn($k) => $pool[$k], $keys);
    }
}
