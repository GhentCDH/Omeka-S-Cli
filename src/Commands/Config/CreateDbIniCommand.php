<?php
namespace OSC\Commands\Config;

use Exception;
use InvalidArgumentException;
use OSC\Commands\AbstractCommand;
use OSC\Helper\DatabaseConfig;

class CreateDbIniCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('config:create-db-ini', 'Create Omeka S database.ini configuration file');
        $this->option('-H --host', 'Database host (default: localhost)', 'strval', 'localhost');
        $this->option('-P --port', 'Database port', 'intval', 3306);
        $this->option('-d --dbname', 'Database name', 'strval');
        $this->option('-u --username', 'Database username', 'strval');
        $this->option('-p --password',  'Database password', 'strval');
    }

    public function execute(string $host, int $port, ?string $username, ?string $password, ?string $dbname): int
    {
        // check options
        if (!$username) {
            throw new InvalidArgumentException('The database username is required.');
        }
        if (!$dbname) {
            throw new InvalidArgumentException('The database name is required.');
        }
        if (!$password) {
            throw new InvalidArgumentException('The database password is required.');
        }

        // check output dir
        $outputPath = $this->getOmekaPath().'/config/database.ini';
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) || !is_writable($outputDir)) {
            throw new Exception('The config directory is not writable.');
        }

        // create config
        $config = DatabaseConfig::fromValues($dbname, $username, $password, $host, $port);
        $config->writeIniFile($outputPath);

        $this->ok("Omeka S database configuration written to {$outputPath}.", true);
        return 0;
    }
}