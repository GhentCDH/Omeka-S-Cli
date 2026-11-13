<?php
namespace OSC\Commands\Config;

use InvalidArgumentException;
use OSC\Commands\AbstractCommand;
use OSC\Helper\Path;

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
        // create config
        $config = $this->generateDatabaseConfig($host, $port, $username, $password, $dbname);
        Path::writeFile($outputPath, $config, "Could not write the database configuration file '{$outputPath}'");

        $this->ok("Omeka S database configuration written to {$outputPath}.", true);
        return 0;
    }

    private function generateDatabaseConfig(string $host, int $port, string $username, string $password, string $dbname): string
    {
        $host = addcslashes($host, '"');
        $username = addcslashes($username, '"');
        $password = addcslashes($password, '"');
        $dbname = addcslashes($dbname, '"');

        return <<<INI
user = "{$username}"
password = "{$password}"
dbname = "{$dbname}"
host = "{$host}"
port = {$port}
; Uncomment and configure if using a Unix socket
; unix_socket = "/path/to/mysql.sock"
; Additional options
; log_path = ""
INI;
    }
}