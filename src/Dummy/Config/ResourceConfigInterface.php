<?php

namespace OSC\Dummy\Config;

interface ResourceConfigInterface {
    public static function defaultConfig(): array;

    public static function fromSource(string $path): static;
    public static function fromDefaultConfig(): static;
}