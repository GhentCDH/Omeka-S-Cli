<?php
namespace Tests\Repository;

use OSC\Cache;
use OSC\Cache\FileCache;
use OSC\Helper\Offline;
use ReflectionProperty;

/** Point OSC\Cache at a temporary directory and start online; undo both afterwards. */
trait TemporaryCache
{
    private string $cacheDir;
    private string|false $savedOffline;

    protected function setUpTemporaryCache(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/osc-test-cache-' . uniqid();
        (new ReflectionProperty(Cache::class, 'cache'))->setValue(null, new FileCache($this->cacheDir, 3600));

        $this->savedOffline = getenv(Offline::ENV);
        self::unsetOffline();
    }

    protected function tearDownTemporaryCache(): void
    {
        (new ReflectionProperty(Cache::class, 'cache'))->setValue(null, null);
        if (is_dir($this->cacheDir)) {
            exec('rm -rf ' . escapeshellarg($this->cacheDir));
        }

        self::unsetOffline();
        if ($this->savedOffline !== false) {
            putenv(Offline::ENV . '=' . $this->savedOffline);
        }
    }

    private static function unsetOffline(): void
    {
        putenv(Offline::ENV);
        unset($_ENV[Offline::ENV]);
    }
}
