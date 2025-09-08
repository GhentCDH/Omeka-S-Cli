<?php
namespace OSC\Commands\Config;

use OSC\Commands\AbstractCommand;

class CreateDbIniCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('config:create-db-ini', 'Create Omeka S database.ini configuration file');
        $this->option('-h --host', 'Database host (default: localhost)', 'strval', 'localhost');
        $this->option('-P --port', 'Database port', 'intval', 3306);
        $this->option('-d --dbname', 'Database name', 'strval');
        $this->option('-u --username', 'Database username', 'strval');
        $this->option('-p --password',  'Database password', 'strval');
    }

    public function execute(string $host, int $port, ?string $username, ?string $password, ?string $dbname): int
    {
        // check options
        if (!$username) {
            throw new \InvalidArgumentException('The database username is required.');
        }
        if (!$dbname) {
            throw new \InvalidArgumentException('The database name is required.');
        }
        if (!$password) {
            throw new \InvalidArgumentException('The database password is required.');
        }

        // check output dir
        $outputPath = $this->getOmekaInstance(false)->getPath().'/config/database.ini';
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) || !is_writable($outputDir)) {
            throw new Exception('The config directory is not writable.');
        }

        // create config
        $config = $this->generateDatabaseConfig($host, $port, $username, $password, $dbname);
        if (file_put_contents($outputPath, $config) === false) {
            throw new Exception("Could not write 'database.ini' config file.");
        }

        $this->ok("Omeka S database configuration created at {$outputPath}", true);
        return 0;
    }

    private function generateDatabaseConfig(string $host, int $port, string $username, string $password, string $dbname): string
    {
        $host = addcslashes($host, '"');
        $username = addcslashes($username, '"');
        $password = addcslashes($password, '"');
        $dbname = addcslashes($dbname, '"');

        return <<<INI
[database]
user = "{$username}"
password = "{$password}"
dbname = "{$dbname}"
host = "{$host}"
port = {$port}

; Uncomment and configure if using a Unix socket
; unix_socket = "/path/to/mysql.sock"

; Additional options
; log_path = ""
; profiler = false

INI;
    }
}