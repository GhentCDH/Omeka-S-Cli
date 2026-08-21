<?php

namespace OSC\Commands\Db;

use Ahc\Cli\Application as App;
use InvalidArgumentException;
use OSC\Commands\AbstractCommand;
use OSC\Database\Engine\EngineFactory;
use OSC\Database\Engine\EngineInterface;
use OSC\Helper\DatabaseConfig;

abstract class AbstractDbCommand extends AbstractCommand
{
    public function __construct(string $_name, string $_desc = '', bool $_allowUnknown = false, ?App $_app = null)
    {
        parent::__construct($_name, $_desc, $_allowUnknown, $_app);

        $this->option('-H --host', 'Database host (overrides database.ini)', 'strval');
        $this->option('-P --port', 'Database port (overrides database.ini)', 'intval');
        $this->option('-d --dbname', 'Database name (overrides database.ini)', 'strval');
        $this->option('-u --username', 'Database username (overrides database.ini)', 'strval');
        $this->option('-p --password', 'Database password (overrides database.ini)', 'strval');
        $this->option('--engine', 'Dump engine: auto, mysqldump or php', 'strval', EngineFactory::ENGINE_AUTO);
    }

    protected function getDatabaseConfig(): DatabaseConfig
    {
        static $config = null;
        if ($config) {
            return $config;
        }

        $values = $this->values();

        return $config = DatabaseConfig::fromOmekaPath($this->getOmekaPath(), [
            'host' => $values['host'] ?? null,
            'port' => $values['port'] ?? null,
            'dbname' => $values['dbname'] ?? null,
            'username' => $values['username'] ?? null,
            'password' => $values['password'] ?? null,
        ]);
    }

    protected function createEngine(): EngineInterface
    {
        $engine = $this->stringValue('engine') ?? EngineFactory::ENGINE_AUTO;

        return EngineFactory::create($this->getDatabaseConfig(), (string) $engine, [$this, 'logFromEngine']);
    }

    public function logFromEngine(string $message, string $level = 'info'): void
    {
        match ($level) {
            'debug' => $this->debug($message, true),
            'warn' => $this->warn($message, true),
            default => $this->info($message, true),
        };
    }

    /**
     * Take back the value a boolean flag swallowed.
     *
     * The parser gives the argument that follows an option to that option, so "db:export --gzip
     * dump.sql" would store 'dump.sql' as the value of --gzip and leave the file name argument
     * empty. Flags listed here are reset to true and their value is returned, so the caller can
     * use it as the argument the user meant it to be.
     *
     * @param string[] $flags Attribute names of the boolean options.
     * @return string[] The values that were swallowed, in the order the flags were given.
     */
    protected function reclaimFlagValues(array $flags): array
    {
        $values = $this->values();
        $reclaimed = [];

        foreach ($flags as $flag) {
            $value = $values[$flag] ?? null;
            if (is_string($value) && $value !== '') {
                $reclaimed[] = $value;
                $this->set($flag, true);
            }
        }

        return $reclaimed;
    }

    /**
     * The value of an option that takes one, or null when it was not given.
     *
     * An option used as a flag ends up holding true, which is a mistake worth reporting.
     */
    protected function stringValue(string $name): ?string
    {
        $value = $this->values()[$name] ?? null;
        if ($value === true) {
            throw new InvalidArgumentException("The option --{$this->toDashCase($name)} requires a value.");
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toDashCase(string $name): string
    {
        return strtolower(preg_replace('~(?<!^)[A-Z]~', '-$0', $name));
    }

    protected function isFlagSet(string $flag): bool
    {
        return (bool) ($this->values()[$flag] ?? false);
    }

    protected function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1073741824, 2) . ' GB';
    }
}
