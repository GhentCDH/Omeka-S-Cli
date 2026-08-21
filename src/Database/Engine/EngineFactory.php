<?php

namespace OSC\Database\Engine;

use Exception;
use InvalidArgumentException;
use OSC\Helper\CommandLocator;
use OSC\Helper\DatabaseConfig;
use OSC\Helper\ProcessRunner;

class EngineFactory
{
    public const ENGINE_AUTO = 'auto';
    public const ENGINE_BINARY = 'mysqldump';
    public const ENGINE_PHP = 'php';

    /**
     * @param string $engine One of auto, mysqldump, php.
     * @param callable|null $logger Called with (string $message, string $level).
     */
    public static function create(DatabaseConfig $config, string $engine = self::ENGINE_AUTO, ?callable $logger = null): EngineInterface
    {
        $engine = strtolower(trim($engine)) ?: self::ENGINE_AUTO;
        if (!in_array($engine, [self::ENGINE_AUTO, self::ENGINE_BINARY, self::ENGINE_PHP], true)) {
            throw new InvalidArgumentException(
                "Unknown engine '{$engine}'. Use one of: auto, mysqldump, php."
            );
        }

        if ($engine === self::ENGINE_PHP) {
            return new PdoEngine($config, $logger);
        }

        // Recent MariaDB packages only ship the mariadb-* names.
        $dump = CommandLocator::find('mysqldump', 'mariadb-dump');
        $client = CommandLocator::find('mysql', 'mariadb');

        // Finding the binaries is not enough: a host that disables proc_open can locate them but
        // never run them, and the PHP engine is exactly the fallback such a host needs.
        $canRunProcesses = ProcessRunner::isAvailable();

        if ($engine === self::ENGINE_BINARY) {
            if (!$canRunProcesses) {
                throw new Exception(
                    'External processes cannot be run because proc_open is disabled on this host. '
                    . 'Use --engine php to dump with PHP only.'
                );
            }
            if (!$dump || !$client) {
                throw new Exception(
                    'The mysqldump/mysql client binaries were not found. Use --engine php to dump with PHP only.'
                );
            }
        }

        if (!$dump || !$client || !$canRunProcesses) {
            if ($logger) {
                $logger(
                    $canRunProcesses
                        ? 'mysqldump not available, using the PHP engine.'
                        : 'proc_open is disabled, using the PHP engine.',
                    'debug'
                );
            }
            return new PdoEngine($config, $logger);
        }

        return new BinaryEngine($config, $dump, $client, $logger);
    }
}
