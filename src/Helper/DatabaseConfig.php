<?php

namespace OSC\Helper;

use Exception;
use InvalidArgumentException;
use PDO;

/**
 * Database credentials of an Omeka S instance, read from config/database.ini.
 *
 * Reading the ini file directly (instead of asking the Omeka service manager for a connection)
 * keeps the database commands usable on an instance that can not be bootstrapped anymore, which
 * is exactly the situation in which a backup or a restore is needed.
 */
class DatabaseConfig
{
    private function __construct(
        private string $dbname,
        private string $user,
        private string $password,
        private string $host,
        private int $port,
        private ?string $unixSocket
    ) {
    }

    /**
     * Build a configuration from explicit values, for an instance that has none yet.
     *
     * @param string      $dbname     Database name
     * @param string      $user       Database user
     * @param string      $password   Database password
     * @param string      $host       Database host
     * @param int         $port       Database port
     * @param string|null $unixSocket Unix socket, when the connection does not use host and port
     *
     * @return self
     */
    public static function fromValues(
        string $dbname,
        string $user,
        string $password,
        string $host = 'localhost',
        int $port = 3306,
        ?string $unixSocket = null
    ): self {
        if (!$dbname) {
            throw new InvalidArgumentException('The database name is required.');
        }
        if (!$user) {
            throw new InvalidArgumentException('The database username is required.');
        }

        return new self($dbname, $user, $password, $host, $port ?: 3306, $unixSocket);
    }

    /**
     * @param array $overrides Optional 'host', 'port', 'dbname', 'username', 'password' overrides.
     */
    public static function fromOmekaPath(string $omekaPath, array $overrides = []): self
    {
        $configFile = rtrim($omekaPath, DIRECTORY_SEPARATOR) . '/config/database.ini';
        $config = [];

        if (file_exists($configFile)) {
            if (!is_readable($configFile)) {
                throw new Exception("The database configuration file '{$configFile}' is not readable.");
            }
            $config = parse_ini_file($configFile);
            if ($config === false) {
                throw new Exception("Could not parse the database configuration file '{$configFile}'.");
            }
        }

        $overrides = array_filter($overrides, fn($value) => $value !== null && $value !== '');

        $dbname = (string) ($overrides['dbname'] ?? $config['dbname'] ?? '');
        $user = (string) ($overrides['username'] ?? $config['user'] ?? '');
        $password = (string) ($overrides['password'] ?? $config['password'] ?? '');
        $host = (string) ($overrides['host'] ?? $config['host'] ?? 'localhost');
        $port = (int) ($overrides['port'] ?? $config['port'] ?? 3306);
        $socket = $config['unix_socket'] ?? null;

        if (!$config && (!$dbname || !$user)) {
            throw new Exception(
                "Could not read the database configuration from '{$configFile}'. "
                . "Create it with 'config:create-db-ini' or pass --dbname/--username/--password."
            );
        }
        if (!$dbname) {
            throw new InvalidArgumentException('The database name is required.');
        }
        if (!$user) {
            throw new InvalidArgumentException('The database username is required.');
        }

        return new self($dbname, $user, $password, $host, $port ?: 3306, $socket ? (string) $socket : null);
    }

    public function getDbname(): string
    {
        return $this->dbname;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUnixSocket(): ?string
    {
        return $this->unixSocket;
    }

    public function getDsn(bool $withDatabase = true): string
    {
        $parts = $this->unixSocket
            ? ['unix_socket=' . $this->unixSocket]
            : ['host=' . $this->host, 'port=' . $this->port];
        if ($withDatabase) {
            $parts[] = 'dbname=' . $this->dbname;
        }
        $parts[] = 'charset=utf8mb4';

        return 'mysql:' . implode(';', $parts);
    }

    public function getPdo(bool $withDatabase = true): PDO
    {
        static $connections = [];

        $key = $withDatabase ? 'db' : 'server';
        if (!isset($connections[$key])) {
            $connections[$key] = new PDO($this->getDsn($withDatabase), $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $connections[$key];
    }

    /**
     * Write these credentials as an Omeka S config/database.ini file.
     *
     * @param string $path Destination file
     *
     * @return void
     *
     * @throws Exception If the file can not be written
     */
    public function writeIniFile(string $path): void
    {
        $escape = fn(string $value) => self::escapeIniValue($value);
        $contents = <<<INI
            user = "{$escape($this->user)}"
            password = "{$escape($this->password)}"
            dbname = "{$escape($this->dbname)}"
            host = "{$escape($this->host)}"
            port = {$this->port}
            ; Uncomment and configure if using a Unix socket
            ; unix_socket = "/path/to/mysql.sock"
            ; Additional options
            ; log_path = ""
            INI;

        // the failure is reported as an exception, the warning would only duplicate it
        if (@file_put_contents($path, $contents) === false) {
            throw new Exception("Could not write the database configuration file '{$path}'.");
        }
    }

    /**
     * Escape a value for a double quoted database.ini entry.
     *
     * The file is read back with parse_ini_file(), by this tool and by Omeka S itself through
     * Laminas\Config\Reader\Ini. That parser expands ${...} from the environment, so a password
     * holding a dollar sign would come back altered, or not parse at all. Escaping the dollar
     * along with the backslash and the double quote keeps every value intact.
     *
     * Note this is not the escaping used for the mysql defaults file below: that one is read by
     * the mysql client, which has its own option file syntax.
     *
     * @param string $value Raw value
     *
     * @return string Escaped value
     */
    private static function escapeIniValue(string $value): string
    {
        return addcslashes($value, '"\\$');
    }

    /**
     * Write a temporary mysql defaults file holding the credentials.
     *
     * Passing the password with --password would expose it in the process list (ps, /proc), so it
     * is handed over in a file that only the current user can read. The caller is responsible for
     * deleting it, and must pass it as the *first* argument of the mysql client:
     * --defaults-extra-file=<path>.
     */
    public function writeDefaultsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'osc_mysql_');
        if ($path === false) {
            throw new Exception('Unable to create a temporary file for the database credentials.');
        }

        // Restrict the permissions before the credentials are written.
        chmod($path, 0600);

        $escape = fn(string $value) => addcslashes($value, "\\\"'\n");
        $lines = [
            '[client]',
            'user=' . $escape($this->user),
            'password=' . $escape($this->password),
        ];
        if ($this->unixSocket) {
            $lines[] = 'socket=' . $escape($this->unixSocket);
        } else {
            $lines[] = 'host=' . $escape($this->host);
            $lines[] = 'port=' . $this->port;
        }

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            @unlink($path);
            throw new Exception('Unable to write the temporary database credentials file.');
        }

        return $path;
    }
}
