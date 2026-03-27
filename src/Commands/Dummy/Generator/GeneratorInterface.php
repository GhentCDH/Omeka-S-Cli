<?php

namespace OSC\Commands\Dummy\Generator;

interface GeneratorInterface
{
    public function generate(): mixed;
    public function getId(): string;
}
