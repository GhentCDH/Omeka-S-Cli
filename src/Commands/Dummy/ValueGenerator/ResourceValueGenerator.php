<?php

namespace OSC\Commands\Dummy\ValueGenerator;

class ResourceValueGenerator implements ValueGeneratorInterface
{
    private array $resourceIds;

    public function __construct(array $resourceIds)
    {
        $this->resourceIds = $resourceIds;
    }

    public function generate(): array
    {
        $id = $this->resourceIds[array_rand($this->resourceIds)];

        return [
            'type'              => 'resource',
            'property_id'       => 'auto',
            'is_public'         => true,
            'value_resource_id' => $id,
        ];
    }
}
