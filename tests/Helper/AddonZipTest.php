<?php
namespace Tests\Helper;

use InvalidArgumentException;
use OSC\Blueprint\SourceFields;
use OSC\Helper\AddonZip;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AddonZip::class)]
#[UsesClass(Path::class)]
class AddonZipTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/addon_zip_' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    /** @param array<string,string> $entries Archive path => content */
    private function zip(array $entries): string
    {
        $path = "{$this->dir}/" . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return $path;
    }

    private static function ini(string $version, string $constraint = '^4.0'): string
    {
        return "[info]\nversion = \"{$version}\"\nomeka_version_constraint = \"{$constraint}\"\n";
    }

    public function testReadsAModule(): void
    {
        $addon = AddonZip::read($this->zip([
            'Foo-1.2.0/config/module.ini' => self::ini('1.2.0', '^4.1'),
            'Foo-1.2.0/Module.php' => "<?php\nnamespace Foo;\n\nclass Module {}\n",
        ]), SourceFields::MODULE);

        $this->assertSame('Foo-1.2.0/', $addon->folder);
        $this->assertSame('1.2.0', $addon->version);
        $this->assertSame('^4.1', $addon->constraint);
        $this->assertSame('Foo', $addon->namespace);
    }

    public function testATheme(): void
    {
        $addon = AddonZip::read($this->zip(['freedom/config/theme.ini' => "[info]\nversion = \"1.0.7\"\n"]), SourceFields::THEME);

        $this->assertSame('freedom/', $addon->folder);
        $this->assertSame('1.0.7', $addon->version);
        $this->assertSame('', $addon->constraint);
        $this->assertNull($addon->namespace);
    }

    public function testReadsTheShallowestIni(): void
    {
        // the nested ini comes first in the archive, but the add-on is the shallowest folder
        $addon = AddonZip::read($this->zip([
            'Foo/vendor/x/config/module.ini' => self::ini('9.9.9'),
            'Foo/vendor/x/Module.php' => "<?php\nnamespace X;\n",
            'Foo/config/module.ini' => self::ini('1.2.0'),
            'Foo/Module.php' => "<?php\nnamespace Foo;\n",
        ]), SourceFields::MODULE);

        $this->assertSame('Foo/', $addon->folder);
        $this->assertSame('1.2.0', $addon->version);
        $this->assertSame('Foo', $addon->namespace);
    }

    public function testAnIniAtTheRoot(): void
    {
        $addon = AddonZip::read($this->zip([
            'config/module.ini' => self::ini('1.0.0'),
            'Module.php' => "<?php\nnamespace Foo;\n",
        ]), SourceFields::MODULE);

        $this->assertSame('', $addon->folder);
        $this->assertSame('Foo', $addon->namespace);
    }

    public function testALookAlikeFolderIsNotTheIni(): void
    {
        $path = $this->zip(['Fooconfig/module.ini' => self::ini('1.0.0')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("no config/module.ini found in {$path}");
        AddonZip::read($path, SourceFields::MODULE);
    }

    public function testAModuleWithoutModulePhpHasNoNamespace(): void
    {
        $addon = AddonZip::read($this->zip(['Foo/config/module.ini' => self::ini('1.0.0')]), SourceFields::MODULE);

        $this->assertNull($addon->namespace);
    }

    public function testRejectsAFileThatIsNotAZip(): void
    {
        $path = "{$this->dir}/broken.zip";
        file_put_contents($path, 'not a zip');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$path} is not a ZIP archive");
        AddonZip::read($path, SourceFields::MODULE);
    }

    public function testRejectsAnArchiveWithoutIni(): void
    {
        $path = $this->zip(['x/readme.txt' => 'hi']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("no config/theme.ini found in {$path}");
        AddonZip::read($path, SourceFields::THEME);
    }

    public function testRejectsAnUnreadableIni(): void
    {
        if (!method_exists(ZipArchive::class, 'setEncryptionName')) {
            $this->markTestSkipped('libzip has no encryption support');
        }
        $path = "{$this->dir}/encrypted.zip";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('Foo/config/module.ini', self::ini('1.0.0'));
        $zip->setEncryptionName('Foo/config/module.ini', ZipArchive::EM_AES_256, 'secret');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("cannot read Foo/config/module.ini in {$path}");
        AddonZip::read($path, SourceFields::MODULE);
    }

    public function testMessagesUseTheGivenName(): void
    {
        $path = "{$this->dir}/broken.zip";
        file_put_contents($path, 'not a zip');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('https://example.org/Foo-1.0.zip is not a ZIP archive');
        AddonZip::read($path, SourceFields::MODULE, 'https://example.org/Foo-1.0.zip');
    }

    public function testRejectsAnIniWithoutVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('config/module.ini has no version');
        AddonZip::read($this->zip(['Foo/config/module.ini' => "[info]\nname = \"Foo\"\n"]), SourceFields::MODULE);
    }

    public function testModuleNamespace(): void
    {
        $this->assertSame('Foo', AddonZip::moduleNamespace("<?php\nnamespace Foo;\n"));
        $this->assertSame('Foo\\Bar', AddonZip::moduleNamespace("<?php\n\nnamespace   Foo\\Bar ;\n"));
        $this->assertNull(AddonZip::moduleNamespace("<?php\nclass Module {}\n"));
    }
}
