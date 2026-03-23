<?php

namespace OSC\Commands\Dummy\Resource;

use Exception;
use Omeka\Entity\Resource;

class ResourcePool
{
    private $api;
    private array $pools = [];

    public function __construct($api)
    {
        $this->api = $api;
    }

    /**
     * Lazy-fetch all IDs for $type. Idempotent.
     */
    public function init(string $type): self
    {
        if (!array_key_exists($type, $this->pools)) {
            $this->pools[$type] = $this->fetch($type);
        }
        return $this;
    }

    /**
     * Validate that $ids exist, checking the pool first and falling back to a direct DB lookup.
     * Any ID that exists is added to the pool; missing IDs throw.
     */
    public function requireIds(string $type, array $ids): self
    {
        $missing = [];

        foreach ($ids as $id) {
            if (isset($this->pools[$type]) && in_array($id, $this->pools[$type], true)) {
                continue;
            }

            try {
                $this->api->read($type, $id, [], ['responseContent' => 'resource']);
                $this->pools[$type][] = $id;
            } catch (Exception) {
                $missing[] = $id;
            }
        }

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                "The following $type IDs do not exist: " . implode(', ', $missing) . '.'
            );
        }

        return $this;
    }

    public function get(string $type): array
    {
        return $this->pools[$type] ?? [];
    }

    private function fetch(string $type): array
    {
        if ($type === 'any') {
            return array_merge(
                $this->fetchType('items'),
                $this->fetchType('item_sets')
            );
        }
        return $this->fetchType($type);
    }

    private function fetchType(string $type): array
    {
        $response = $this->api->search($type, ['limit' => 1000], ['responseContent' => 'resource']);
        if ($response->getTotalResults() === 0) {
            return [];
        }
        /** @var Resource[] $content */
        $content = $response->getContent();
        return array_map(fn($r) => $r->getId(), $content);
    }
}
