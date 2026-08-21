<?php

namespace OSC\Helper;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Locate external commands without failing loudly when they are absent.
 *
 * A missing mysqldump is a normal situation here (there is a pure PHP fallback), so detection has
 * to stay quiet. The lookup walks PATH and checks the files directly, so it also works on hosts
 * where proc_open, exec and shell_exec are all disabled. Finding a command says nothing about
 * being able to run it: ask ProcessRunner::isAvailable() for that.
 */
class CommandLocator
{
    /**
     * Paths already looked up, keyed by command name.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    private static ?ExecutableFinder $finder = null;

    /**
     * Return the path of the first command found, or null when none is available.
     *
     * @param string ...$commands Command names, in order of preference
     *
     * @return string|null
     */
    public static function find(string ...$commands): ?string
    {
        foreach ($commands as $command) {
            $path = self::findOne($command);
            if ($path) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Return the path of a single command, or null when it is not available.
     *
     * @param string $command Command name
     *
     * @return string|null
     */
    public static function findOne(string $command): ?string
    {
        if (array_key_exists($command, self::$cache)) {
            return self::$cache[$command];
        }

        self::$finder ??= new ExecutableFinder();

        return self::$cache[$command] = self::$finder->find($command);
    }

    /**
     * Forget the cached lookups.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
