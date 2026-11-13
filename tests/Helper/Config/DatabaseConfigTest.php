<?php
namespace Tests\Helper\Config;

use OSC\Helper\Config\DatabaseConfig;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseConfig::class)]
#[UsesClass(Path::class)]
class DatabaseConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/database_config_' . uniqid();
        mkdir("{$this->dir}/config", 0700, true);
    }

    protected function tearDown(): void
    {
        Path::removeFolder($this->dir);
    }

    public function testReadsTheCredentialsFromDatabaseIni(): void
    {
        file_put_contents("{$this->dir}/config/database.ini", "user = \"omeka\"\npassword = \"secret\"\ndbname = \"omeka_db\"\nhost = \"db\"\n");
        $config = DatabaseConfig::fromOmekaPath($this->dir);
        $this->assertStringContainsString('dbname=omeka_db', $config->getDsn());
    }

    public function testABrokenDatabaseIniNamesTheFileAndTheSyntaxError(): void
    {
        $path = "{$this->dir}/config/database.ini";
        file_put_contents($path, "user = \"x\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not load the database configuration file '{$path}': syntax error");
        DatabaseConfig::fromOmekaPath($this->dir);
    }
}
