<?php

namespace OSC\Commands\Dummy\Generator\Property;

use OSC\Commands\Dummy\Resource\ResourceClassResolver;

class ResourceClassGenerator implements PropertyGeneratorInterface
{
    public const ID = 'resource_class';

    private array $classIds;

    public function __construct(array $config, ResourceClassResolver $resolver)
    {
        if (empty($config['values']) || !is_array($config['values'])) {
            throw new \InvalidArgumentException(
                "o:resource_class 'values' must be a non-empty array."
            );
        }

        $this->classIds = $resolver->resolve($config['values'] ?? null);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function generate(): ?array
    {
        if (empty($this->classIds)) {
            return null;
        }

        $id = $this->classIds[array_rand($this->classIds)];

        return ['o:id' => $id];
    }
}
