<?php
namespace Tests\Addon;

use OSC\Addon\AddonIni;
use OSC\Addon\AddonType;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddonIni::class)]
#[CoversClass(AddonType::class)]
#[UsesClass(Path::class)]
class AddonIniTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/addon_ini_' . uniqid();
        mkdir($this->dir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    public function testReadsTheInfoSection(): void
    {
        file_put_contents(
            $this->dir . '/config/module.ini',
            "[info]\nname = \"Common\"\nversion = \" 3.4.1 \"\nomeka_version_constraint = \"^4.0\"\n"
        );

        $ini = AddonIni::fromAddonDir($this->dir, AddonType::Module);

        $this->assertSame('Common', $ini->name());
        $this->assertSame('3.4.1', $ini->version());
        $this->assertSame('^4.0', $ini->omekaConstraint());
        $this->assertSame('Common', $ini->info()['name']);
    }

    public function testIgnoresOtherSections(): void
    {
        // a theme's [config] describes its settings form
        file_put_contents(
            $this->dir . '/config/theme.ini',
            "[info]\nname = \"Freedom\"\nversion = \"1.0.7\"\n[config]\nelements.logo.name = \"logo\"\n"
        );

        $ini = AddonIni::fromAddonDir($this->dir, AddonType::Theme);

        $this->assertSame('Freedom', $ini->name());
        $this->assertArrayNotHasKey('elements', $ini->info());
    }

    public function testReadsAFlatIniAsTheInfo(): void
    {
        file_put_contents($this->dir . '/config/module.ini', "version = \"1.0.0\"\n");

        $this->assertSame('1.0.0', AddonIni::fromAddonDir($this->dir, AddonType::Module)->version());
    }

    public function testAbsentOrEmptyValuesAreNull(): void
    {
        file_put_contents($this->dir . '/config/module.ini', "[info]\nname = \"Foo\"\nomeka_version_constraint = \"\"\n");

        $ini = AddonIni::fromAddonDir($this->dir, AddonType::Module);

        $this->assertNull($ini->version());
        $this->assertNull($ini->omekaConstraint());
    }

    public function testAMissingFileIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No valid theme.ini found');
        AddonIni::fromAddonDir($this->dir, AddonType::Theme);
    }

    public function testAnEmptyFileIsNotFound(): void
    {
        file_put_contents($this->dir . '/config/module.ini', '');

        $this->expectException(NotFoundException::class);
        AddonIni::fromFile($this->dir . '/config/module.ini');
    }

    public function testABrokenIniNamesTheParseError(): void
    {
        file_put_contents($this->dir . '/config/module.ini', "[info\n");

        try {
            AddonIni::fromAddonDir($this->dir, AddonType::Module);
            $this->fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('No valid module.ini found', $e->getMessage());
            $this->assertStringContainsString('syntax error', $e->getMessage());
            $this->assertSame(1, substr_count($e->getMessage(), $this->dir), 'the path is named once');
        }
    }

    public function testTheTypeNamesItsFolderAndIni(): void
    {
        $this->assertSame('modules', AddonType::Module->dir());
        $this->assertSame('theme.ini', AddonType::Theme->iniName());
    }
}
