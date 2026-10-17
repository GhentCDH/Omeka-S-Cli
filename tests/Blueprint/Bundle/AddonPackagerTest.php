<?php
namespace Tests\Blueprint\Bundle;

use Exception;
use OSC\Blueprint\Bundle\AddonPackager;
use OSC\Blueprint\Bundle\PackagedAddon;
use OSC\Blueprint\SourceFields;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ResolvedAddon;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AddonPackager::class)]
#[UsesClass(PackagedAddon::class)]
#[UsesClass(ResolvedAddon::class)]
#[UsesClass(ZipDownloader::class)]
#[UsesClass(GitDownloader::class)]
#[UsesClass(Path::class)]
#[UsesClass(ResourceFetcher::class)]
class AddonPackagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp_packager_' . uniqid();
        mkdir($this->dir . '/bundle', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    /** @param array<string,string> $entries Archive path => content */
    private function zip(string $name, array $entries): string
    {
        $path = "{$this->dir}/{$name}";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return $path;
    }

    /** A module release ZIP whose single folder is $folder. */
    private function moduleZip(string $folder, string $namespace, string $version, string $constraint = '^4.0', array $extra = []): string
    {
        return $this->zip("{$folder}.zip", array_merge([
            "{$folder}/config/module.ini" => "[info]\nname = \"{$namespace}\"\nversion = \"{$version}\"\nomeka_version_constraint = \"{$constraint}\"\n",
            "{$folder}/Module.php" => "<?php\nnamespace {$namespace};\n\nclass Module {}\n",
        ], $extra));
    }

    private function themeZip(string $folder, string $label, string $version): string
    {
        return $this->zip("{$folder}.zip", [
            "{$folder}/config/theme.ini" => "[info]\nname = \"{$label}\"\nversion = \"{$version}\"\nomeka_version_constraint = \"^4.0\"\n",
        ]);
    }

    private function zipSource(string $path): ResolvedAddon
    {
        return new ResolvedAddon(new ZipDownloader($path), ResolvedAddon::ZIP);
    }

    private function package(
        string $kind,
        string $name,
        ResolvedAddon $resolved,
        ?string $pinned = null,
        bool $allowIncompatible = false,
    ): PackagedAddon {
        return (new AddonPackager())
            ->package($kind, $name, $resolved, $pinned, '4.1.1', $allowIncompatible, "{$this->dir}/bundle");
    }

    public function testCopiesTheReleaseZipUnchanged(): void
    {
        $zip = $this->moduleZip('release-1.2.0', 'Foo', '1.2.0');

        $packaged = $this->package(SourceFields::MODULE, 'foo', $this->zipSource($zip));

        $this->assertSame('Foo', $packaged->name);
        $this->assertSame('1.2.0', $packaged->version);
        $this->assertSame('modules/Foo-1.2.0.zip', $packaged->zipPath);
        $this->assertSame(sha1_file($zip), sha1_file("{$this->dir}/bundle/{$packaged->zipPath}"));
        $this->assertSame(["renamed to 'Foo', its folder name"], $packaged->warnings);
    }

    public function testAZipWithoutTopFolderIsCopied(): void
    {
        $zip = $this->zip('flat.zip', [
            'config/module.ini' => "[info]\nversion = \"1.0.0\"\nomeka_version_constraint = \"^4.0\"\n",
            'Module.php' => "<?php\nnamespace Foo;\n",
        ]);

        $packaged = $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip));

        $this->assertSame('modules/Foo-1.0.0.zip', $packaged->zipPath);
        $this->assertSame(sha1_file($zip), sha1_file("{$this->dir}/bundle/{$packaged->zipPath}"));
    }

    public function testReadsTheShallowestIni(): void
    {
        // the nested ini comes first in the archive, but the add-on is the shallowest folder
        $zip = $this->zip('Foo.zip', [
            'Foo/vendor/x/config/module.ini' => "[info]\nversion = \"9.9.9\"\n",
            'Foo/vendor/x/Module.php' => "<?php\nnamespace X;\n",
            'Foo/config/module.ini' => "[info]\nversion = \"1.2.0\"\nomeka_version_constraint = \"^4.0\"\n",
            'Foo/Module.php' => "<?php\nnamespace Foo;\n",
        ]);

        $packaged = $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip));

        $this->assertSame('Foo', $packaged->name);
        $this->assertSame('1.2.0', $packaged->version);
    }

    public function testRejectsAFileThatIsNotAZip(): void
    {
        $path = "{$this->dir}/broken.zip";
        file_put_contents($path, 'not a zip');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("{$path} is not a ZIP archive");
        $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($path));
    }

    public function testAGitSourceIsRefused(): void
    {
        $resolved = new ResolvedAddon(new GitDownloader('https://example.org/foo.git'), ResolvedAddon::GIT);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("a git source can't be bundled");
        $this->package(SourceFields::MODULE, 'Foo', $resolved);
    }

    public function testNoDownloadIsLeftBehindOnError(): void
    {
        try {
            $this->package(SourceFields::MODULE, 'Bar', $this->zipSource($this->moduleZip('Foo', 'Foo', '1.2.0')));
            $this->fail('the namespace mismatch must be refused');
        } catch (Exception $e) {
            $this->assertStringContainsString("declares namespace 'Foo'", $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir("{$this->dir}/bundle"), ['.', '..'])));
    }

    public function testRejectsAModuleWhoseNamespaceIsNotTheName(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Module.php declares namespace 'Foo', but the blueprint name is 'Bar'");
        $this->package(SourceFields::MODULE, 'Bar', $this->zipSource($this->moduleZip('Foo', 'Foo', '1.2.0')));
    }

    public function testRejectsAnIncompatibleAddonUnlessAllowed(): void
    {
        $zip = $this->moduleZip('Foo', 'Foo', '1.2.0', '^5.0');

        $allowed = $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip), null, true);
        $this->assertSame(['requires Omeka S ^5.0, but the bundle targets 4.1.1'], $allowed->warnings);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('--allow-incompatible');
        $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip));
    }

    public function testAZipWinsOverAPinnedVersion(): void
    {
        $packaged = $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($this->moduleZip('Foo', 'Foo', '1.2.0')), '1.0.0');

        $this->assertSame('1.2.0', $packaged->version);
        $this->assertSame(['version 1.0.0 is pinned, but the ZIP contains 1.2.0; the ZIP wins'], $packaged->warnings);
    }

    public function testACatalogThemeUsesTheCatalogFolderName(): void
    {
        $zip = $this->themeZip('freedom-v1.0.7', 'Freedom', '1.0.7');
        $resolved = new ResolvedAddon(new ZipDownloader($zip), ResolvedAddon::CATALOG, 'freedom', '1.0.7');

        $packaged = $this->package(SourceFields::THEME, 'Freedom', $resolved);

        $this->assertSame('freedom', $packaged->name);
        $this->assertSame('themes/freedom-1.0.7.zip', $packaged->zipPath);
        $this->assertSame(sha1_file($zip), sha1_file("{$this->dir}/bundle/{$packaged->zipPath}"));
    }

    public function testASourcedThemeKeepsTheBlueprintName(): void
    {
        $packaged = $this->package(SourceFields::THEME, 'my-theme', $this->zipSource($this->themeZip('whatever-1.0', 'My Theme', '1.0')));

        $this->assertSame('my-theme', $packaged->name);
        $this->assertSame([], $packaged->warnings);
    }

    public function testRejectsAnArchiveWithoutIni(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no config/module.ini found');
        $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($this->zip('x.zip', ['x/readme.txt' => 'hi'])));
    }

    public function testRejectsAnIniWithoutVersion(): void
    {
        $zip = $this->zip('Foo.zip', ['Foo/config/module.ini' => "[info]\nname = \"Foo\"\n", 'Foo/Module.php' => "<?php\nnamespace Foo;\n"]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('config/module.ini has no version');
        $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip));
    }
}
