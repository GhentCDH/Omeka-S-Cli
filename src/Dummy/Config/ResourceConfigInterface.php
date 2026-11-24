<?php

namespace OSC\Dummy\Config;

use OSC\Source\Location;

interface ResourceConfigInterface {
    public static function defaultConfig(): array;

    public static function fromSource(Location $source): static;
    public static function fromDefaultConfig(): static;
}