<?php
namespace Tests\Addon;

use OSC\Addon\AddonIni;
use OSC\Addon\AddonType;
use OSC\Addon\AddonVersion;
use OSC\Addon\InstalledAddons;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstalledAddons::class)]
#[UsesClass(AddonIni::class)]
#[UsesClass(AddonType::class)]
#[UsesClass(AddonVersion::class)]
#[UsesClass(Path::class)]
class InstalledAddonsTest extends TestCase
{
    private string $dir;
    private InstalledAddons $installed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/installed_addons_' . uniqid();
        mkdir($this->dir . '/modules/Common/config', 0777, true);
        file_put_contents($this->dir . '/modules/Common/config/module.ini', "[info]\nversion = \"3.4.1\"\n");
        mkdir($this->dir . '/themes/default', 0777, true);
        $this->installed = new InstalledAddons($this->dir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    public function testPathAndExists(): void
    {
        $this->assertSame($this->dir . '/modules/Common', $this->installed->path(AddonType::Module, 'Common'));
        $this->assertTrue($this->installed->exists(AddonType::Module, 'Common'));
        $this->assertTrue($this->installed->exists(AddonType::Theme, 'default'));
        $this->assertFalse($this->installed->exists(AddonType::Module, 'Missing'));
        $this->assertFalse($this->installed->exists(AddonType::Module, ''));
    }

    public function testFindMatchesTheFolderNameIgnoringCase(): void
    {
        // a repository name is matched case-insensitively, so 'common' finds the folder 'Common'
        $this->assertSame('Common', $this->installed->find(AddonType::Module, 'Common'));
        $this->assertSame('Common', $this->installed->find(AddonType::Module, 'common'));
        $this->assertSame('default', $this->installed->find(AddonType::Theme, 'DEFAULT'));
        $this->assertNull($this->installed->find(AddonType::Module, 'Missing'));
        $this->assertNull($this->installed->find(AddonType::Module, ''));
    }

    public function testFindOnlyLooksAtFoldersInsideTheAddonDirectory(): void
    {
        $this->assertNull($this->installed->find(AddonType::Module, '.'));
        $this->assertNull($this->installed->find(AddonType::Module, '..'));
        $this->assertNull($this->installed->find(AddonType::Module, '../themes/default'));
    }

    public function testFindIgnoresFiles(): void
    {
        file_put_contents($this->dir . '/modules/Notes.txt', '');

        $this->assertNull($this->installed->find(AddonType::Module, 'notes.txt'));
    }

    public function testVersionIsReadFromTheIni(): void
    {
        $this->assertSame('3.4.1', $this->installed->version(AddonType::Module, 'Common'));
        // a folder without an ini, and a missing add-on, have no version
        $this->assertNull($this->installed->version(AddonType::Theme, 'default'));
        $this->assertNull($this->installed->version(AddonType::Module, 'Missing'));
    }

    public function testVersionDiffers(): void
    {
        $this->assertFalse($this->installed->versionDiffers(AddonType::Module, 'Common', 'v3.4.1'));
        $this->assertTrue($this->installed->versionDiffers(AddonType::Module, 'Common', '3.5.0'));
        // no pin, or nothing on disk, is not a difference
        $this->assertFalse($this->installed->versionDiffers(AddonType::Module, 'Common', null));
        $this->assertFalse($this->installed->versionDiffers(AddonType::Module, 'Missing', '1.0.0'));
    }
}
