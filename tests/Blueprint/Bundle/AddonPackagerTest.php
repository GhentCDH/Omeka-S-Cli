<?php
namespace Tests\Blueprint\Bundle;

use Closure;
use Exception;
use LogicException;
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
        ?Closure $composer = null,
    ): PackagedAddon {
        $composer ??= fn(string $dir) => throw new LogicException('composer must not run');
        return (new AddonPackager($composer))
            ->package($kind, $name, $resolved, $pinned, '4.1.1', $allowIncompatible, "{$this->dir}/bundle");
    }

    /** @return string[] The entry names of a ZIP in the bundle */
    private function entries(PackagedAddon $packaged): array
    {
        $zip = new ZipArchive();
        $zip->open("{$this->dir}/bundle/{$packaged->zipPath}");
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $names;
    }

    public function testRepacksAModuleUnderItsNamespace(): void
    {
        $packaged = $this->package(SourceFields::MODULE, 'foo', $this->zipSource($this->moduleZip('release-1.2.0', 'Foo', '1.2.0')));

        $this->assertSame('Foo', $packaged->name);
        $this->assertSame('1.2.0', $packaged->version);
        $this->assertSame('modules/Foo-1.2.0.zip', $packaged->zipPath);
        $this->assertContains('Foo/config/module.ini', $this->entries($packaged));
        $this->assertContains('Foo/Module.php', $this->entries($packaged));
        $this->assertSame(["renamed to 'Foo', its folder name"], $packaged->warnings);
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

    public function testInstallsComposerDependenciesWhenVendorIsMissing(): void
    {
        $zip = $this->moduleZip('Foo', 'Foo', '1.2.0', '^4.0', ['Foo/composer.lock' => '{}']);
        $composer = function (string $dir): void {
            mkdir("{$dir}/vendor");
            file_put_contents("{$dir}/vendor/autoload.php", '<?php');
        };

        $packaged = $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip), null, false, $composer);

        $this->assertContains('Foo/vendor/autoload.php', $this->entries($packaged));
    }

    public function testSkipsComposerWhenVendorIsShipped(): void
    {
        $zip = $this->moduleZip('Foo', 'Foo', '1.2.0', '^4.0', ['Foo/composer.lock' => '{}', 'Foo/vendor/autoload.php' => '<?php']);

        // the default test closure throws if composer runs
        $packaged = $this->package(SourceFields::MODULE, 'Foo', $this->zipSource($zip));

        $this->assertContains('Foo/vendor/autoload.php', $this->entries($packaged));
    }

    public function testACatalogThemeUsesTheCatalogFolderName(): void
    {
        $zip = $this->themeZip('freedom-v1.0.7', 'Freedom', '1.0.7');
        $resolved = new ResolvedAddon(new ZipDownloader($zip), ResolvedAddon::CATALOG, 'freedom', '1.0.7');

        $packaged = $this->package(SourceFields::THEME, 'Freedom', $resolved);

        $this->assertSame('freedom', $packaged->name);
        $this->assertSame('themes/freedom-1.0.7.zip', $packaged->zipPath);
        $this->assertContains('freedom/config/theme.ini', $this->entries($packaged));
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

    public function testAGitSourceIsZippedWithoutGitMetadata(): void
    {
        exec('command -v git', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('git is not available');
        }
        $repo = "{$this->dir}/repo";
        mkdir("{$repo}/config", 0777, true);
        file_put_contents("{$repo}/config/module.ini", "[info]\nversion = \"2.0.0\"\nomeka_version_constraint = \"^4.0\"\n");
        file_put_contents("{$repo}/Module.php", "<?php\nnamespace Foo;\n");
        exec(sprintf(
            'cd %s && git init -q && git add . && git -c user.name=t -c user.email=t@example.org commit -qm init && git tag 2.0.0',
            escapeshellarg($repo)
        ));

        $resolved = new ResolvedAddon(new GitDownloader($repo, '2.0.0'), ResolvedAddon::GIT);
        $packaged = $this->package(SourceFields::MODULE, 'Foo', $resolved);

        $this->assertSame('modules/Foo-2.0.0.zip', $packaged->zipPath);
        $this->assertSame([], array_filter($this->entries($packaged), fn($e) => str_starts_with($e, 'Foo/.git')));
    }
}
