<?php

namespace OSC\Omeka;

class OmekaInstanceFactory
{
    protected static ?OmekaInstance $instance = null;
    public static function createSingleton(string $path): OmekaInstance
    {
        if (static::$instance) {
            return static::$instance;
        }

        static::$instance = new OmekaInstance($path);
        static::$instance->init();
        return static::$instance;
    }
}