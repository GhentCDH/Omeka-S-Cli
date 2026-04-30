<?php

namespace OSC\Commands\Dummy\Generator\Property;

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
        return (bool) random_int(0, 1);
    }
}
