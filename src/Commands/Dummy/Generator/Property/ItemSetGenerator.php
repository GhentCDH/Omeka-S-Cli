<?php

namespace OSC\Commands\Dummy\Generator\Property;

use OSC\Commands\Dummy\Resource\ResourcePool;

class ItemSetGenerator implements PropertyGeneratorInterface
{
    public function __construct(private array $config, private ResourcePool $pool)
    {
        if (isset($config['values'])) {
            if (empty($config['values']) || !is_array($config['values'])) {
                throw new \InvalidArgumentException(
                    "o:item_set 'values' must be a non-empty array."
                );
            }
            $pool->requireIds('item_sets', $config['values']);
            if (isset($config['min']) || isset($config['max'])) {
                if (!isset($config['min']) || !is_int($config['min'])) {
                    throw new \InvalidArgumentException(
                        "o:item_set requires an integer 'min' when 'max' is set."
                    );
                }
                if (!isset($config['max']) || !is_int($config['max'])) {
                    throw new \InvalidArgumentException(
                        "o:item_set requires an integer 'max' when 'min' is set."
                    );
                }
            }
        } else {
            if (!isset($config['min']) || !is_int($config['min'])) {
                throw new \InvalidArgumentException(
                    "o:item_set requires an integer 'min'."
                );
            }
            if (!isset($config['max']) || !is_int($config['max'])) {
                throw new \InvalidArgumentException(
                    "o:item_set requires an integer 'max'."
                );
            }
            $pool->init('item_sets');
        }
    }

    public function getId(): string
    {
        return "item_set";
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
