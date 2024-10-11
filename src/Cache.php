<?php
namespace OSC;

use OSC\Cache\CacheInterface;
use OSC\Cache\FileCache;

class Cache
{
    protected static $cache;

    public static function getCache(): CacheInterface
    {
        if (!isset(self::$cache)) {
            if (getenv('HOME')) {
                $cacheHome = getenv('HOME') . '/.cache';
            } else {
                $cacheHome = sys_get_temp_dir();
            }
            $cacheDir = "$cacheHome/omeka-s-cli";


            self::$cache = new FileCache($cacheDir, 3600*24);
        }

        return self::$cache;
    }
}