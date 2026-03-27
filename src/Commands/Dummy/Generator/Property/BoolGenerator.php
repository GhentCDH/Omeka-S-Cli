<?php

namespace OSC\Commands\Dummy\Generator\Property;

use OSC\Commands\Dummy\Resource\ResourceClassResolver;

class BoolGenerator implements PropertyGeneratorInterface
{
    public function __construct(array $config)
    {
    }

    public function getId(): string
    {
        return "bool";
    }

    public function generate(): bool
    {
        $values = [ true, false];
        return $values[array_rand($values)];
    }
}
