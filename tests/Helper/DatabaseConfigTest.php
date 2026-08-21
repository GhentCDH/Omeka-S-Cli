<?php

namespace OSC\Tests\Helper;

use Exception;
use InvalidArgumentException;
use OSC\Helper\DatabaseConfig;
use Laminas\Config\Reader\Ini as IniReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseConfig::class)]
class DatabaseConfigTest extends TestCase
{
    private string $omekaPath;

    protected function setUp(): void
    {
        $this->omekaPath = sys_get_temp_dir() . '/osc-db-config-' . uniqid();
        mkdir($this->omekaPath . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->omekaPath . '/config/database.ini');
        @rmdir($this->omekaPath . '/config');
        @rmdir($this->omekaPath);
    }

    private function writeIni(string $content): void
    {
        file_put_contents($this->omekaPath . '/config/database.ini', $content);
    }

    public function testReadsTheIniFile(): void
    {
        $this->writeIni(<<<INI
        user = "omeka"
        password = "secret"
        dbname = "omeka_db"
        host = "db"
        port = 3307
        INI);

        $config = DatabaseConfig::fromOmekaPath($this->omekaPath);

        $this->assertSame('omeka_db', $config->getDbname());
        $this->assertSame('omeka', $config->getUser());
        $this->assertSame('db', $config->getHost());
        $this->assertSame(3307, $config->getPort());
        $this->assertNull($config->getUnixSocket());
        $this->assertSame('mysql:host=db;port=3307;dbname=omeka_db;charset=utf8mb4', $config->getDsn());
        $this->assertSame('mysql:host=db;port=3307;charset=utf8mb4', $config->getDsn(false));
    }

    public function testUsesTheUnixSocketWhenConfigured(): void
    {
        $this->writeIni(<<<INI
        user = "omeka"
        password = "secret"
        dbname = "omeka_db"
        host = "localhost"
        unix_socket = "/run/mysqld/mysqld.sock"
        INI);

        $config = DatabaseConfig::fromOmekaPath($this->omekaPath);

        $this->assertSame('/run/mysqld/mysqld.sock', $config->getUnixSocket());
        $this->assertSame('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=omeka_db;charset=utf8mb4', $config->getDsn());
    }

    public function testOverridesWinOverTheIniFile(): void
    {
        $this->writeIni(<<<INI
        user = "omeka"
        password = "secret"
        dbname = "omeka_db"
        host = "db"
        INI);

        $config = DatabaseConfig::fromOmekaPath($this->omekaPath, [
            'dbname' => 'other',
            'username' => 'root',
            'host' => '127.0.0.1',
            'port' => 3308,
            'password' => null,
        ]);

        $this->assertSame('other', $config->getDbname());
        $this->assertSame('root', $config->getUser());
        $this->assertSame('127.0.0.1', $config->getHost());
        $this->assertSame(3308, $config->getPort());
    }

    public function testFallsBackToTheDefaultPortAndHost(): void
    {
        $this->writeIni(<<<INI
        user = "omeka"
        password = ""
        dbname = "omeka_db"
        INI);

        $config = DatabaseConfig::fromOmekaPath($this->omekaPath);

        $this->assertSame('localhost', $config->getHost());
        $this->assertSame(3306, $config->getPort());
    }

    public function testFailsWithoutConfigurationFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('~config:create-db-ini~');

        DatabaseConfig::fromOmekaPath($this->omekaPath);
    }

    public function testFailsWithoutDatabaseName(): void
    {
        $this->writeIni('user = "omeka"');

        $this->expectException(InvalidArgumentException::class);

        DatabaseConfig::fromOmekaPath($this->omekaPath);
    }

    public function testAcceptsOverridesWithoutConfigurationFile(): void
    {
        $config = DatabaseConfig::fromOmekaPath($this->omekaPath, [
            'dbname' => 'omeka_db',
            'username' => 'omeka',
            'password' => 'secret',
        ]);

        $this->assertSame('omeka_db', $config->getDbname());
    }

    public function testWritesAPrivateDefaultsFile(): void
    {
        $this->writeIni(<<<INI
        user = "omeka"
        password = "se'cret"
        dbname = "omeka_db"
        host = "db"
        port = 3307
        INI);

        $config = DatabaseConfig::fromOmekaPath($this->omekaPath);
        $path = $config->writeDefaultsFile();

        try {
            $content = file_get_contents($path);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
            $this->assertStringContainsString('[client]', $content);
            $this->assertStringContainsString('user=omeka', $content);
            $this->assertStringContainsString("password=se\\'cret", $content);
            $this->assertStringContainsString('host=db', $content);
            $this->assertStringContainsString('port=3307', $content);
        } finally {
            @unlink($path);
        }
    }

    public static function awkwardValueProvider(): array
    {
        return [
            'plain' => ['p4ssw0rd'],
            'double quote' => ['pa"ss'],
            'single quote' => ["pa'ss"],
            'backslash' => ['pa\\ss'],
            'trailing backslash' => ['pass\\'],
            'dollar' => ['pa$ss'],
            'environment variable' => ['pa${HOME}ss'],
            'empty braces' => ['p${}s'],
            'semicolon' => ['pa;ss'],
            'hash' => ['pa#ss'],
            'newline' => ["pa\nss"],
            'equals' => ['pa=ss'],
            'brackets' => ['[pass]'],
        ];
    }

    /**
     * @param string $password Password to round trip
     */
    #[DataProvider('awkwardValueProvider')]
    public function testWritesAnIniFileThatReadsBackUnchanged(string $password): void
    {
        $path = $this->omekaPath . '/config/database.ini';

        $config = DatabaseConfig::fromValues('omeka_db', 'omeka', $password, 'db', 3307);
        $config->writeIniFile($path);

        $config = DatabaseConfig::fromOmekaPath($this->omekaPath);
        $this->assertSame('omeka_db', $config->getDbname());
        $this->assertSame('omeka', $config->getUser());
        $this->assertSame('db', $config->getHost());
        $this->assertSame(3307, $config->getPort());

        // the password is not exposed by a getter, so check it through the parsed file
        $parsed = parse_ini_file($path);
        $this->assertIsArray($parsed, 'the file must be parseable');
        $this->assertSame($password, $parsed['password']);
    }

    /**
     * Omeka S reads config/database.ini with Laminas\Config\Reader\Ini, so whatever this tool
     * writes has to survive that reader too, not just parse_ini_file().
     *
     * @param string $password Password to round trip
     */
    #[DataProvider('awkwardValueProvider')]
    public function testTheFileIsReadableByOmeka(string $password): void
    {
        $path = $this->omekaPath . '/config/database.ini';

        $config = DatabaseConfig::fromValues('omeka_db', 'omeka', $password, 'db', 3307);
        $config->writeIniFile($path);

        $read = (new IniReader())->fromFile($path);

        $this->assertSame($password, $read['password']);
        $this->assertSame('omeka', $read['user']);
        $this->assertSame('omeka_db', $read['dbname']);
        $this->assertSame('db', $read['host']);
        $this->assertSame(3307, (int) $read['port']);
    }

    public function testWriteIniFileFailsOnAnUnwritablePath(): void
    {
        $this->expectException(Exception::class);

        $config = DatabaseConfig::fromValues('omeka_db', 'omeka', 'secret');
        $config->writeIniFile($this->omekaPath . '/nope/database.ini');
    }

    public function testFromValuesRequiresNameAndUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromValues('', 'omeka', 'secret');
    }

    public function testFromValuesRequiresUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromValues('omeka_db', '', 'secret');
    }
}
