<?php
namespace Tests\Blueprint;

use OSC\Blueprint\CoreInstaller;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CoreInstaller::class)]
#[UsesClass(Path::class)]
class CoreInstallerIniTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/core_installer_ini_' . uniqid();
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        if (is_file("{$this->dir}/database.ini")) {
            chmod("{$this->dir}/database.ini", 0600);
        }
        Path::removeFolder($this->dir);
    }

    private function ini(string $contents): string
    {
        file_put_contents("{$this->dir}/database.ini", $contents);
        return "{$this->dir}/database.ini";
    }

    public function testAMissingIniHasNoCredentials(): void
    {
        $this->assertFalse(CoreInstaller::hasCredentials("{$this->dir}/database.ini"));
    }

    public function testTheShippedTemplateHasNoCredentials(): void
    {
        $this->assertFalse(CoreInstaller::hasCredentials($this->ini("user = \"\"\npassword = \"\"\ndbname = \"\"\nhost = \"\"\n")));
    }

    public function testAFilledIniHasCredentials(): void
    {
        $this->assertTrue(CoreInstaller::hasCredentials($this->ini("user = \"omeka\"\npassword = \"x\"\ndbname = \"omeka\"\nhost = \"db\"\n")));
    }

    public function testABrokenIniIsAnError(): void
    {
        $path = $this->ini("user = \"x\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not load the database configuration file '{$path}': syntax error");
        CoreInstaller::hasCredentials($path);
    }

    public function testAnUnreadableIniIsAnError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read any file');
        }
        $path = $this->ini("user = \"omeka\"\ndbname = \"omeka\"\n");
        chmod($path, 0000);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not load the database configuration file '{$path}': Permission denied.");
        CoreInstaller::hasCredentials($path);
    }
}
