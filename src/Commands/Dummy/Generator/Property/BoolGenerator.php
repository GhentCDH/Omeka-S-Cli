<?php

namespace OSC\Commands\Dummy\Generator\Property;

use OSC\Commands\Dummy\Resource\ResourceClassResolver;

class BoolGenerator implements PropertyGeneratorInterface
{
    public const ID = 'boolean';

    public function __construct(array $config)
    {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function generate(): bool
    {
        $values = [ true, false];
        return $values[array_rand($values)];
    }
}
