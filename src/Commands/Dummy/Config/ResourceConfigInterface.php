<?php

namespace OSC\Commands\Dummy\Config;

interface ResourceConfigInterface {
    public static function defaultConfig(): array;

    public static function fromFile(string $path): static;
    public static function fromDefaultConfig(): static;
}