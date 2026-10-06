<?php
namespace Tests\Blueprint;

use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintApplier;
use OSC\Commands\AbstractCommand;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(BlueprintApplier::class)]
#[UsesClass(Blueprint::class)]
#[UsesClass(ZipDownloader::class)]
#[UsesClass(Path::class)]
#[UsesClass(ResourceFetcher::class)]
class BlueprintApplierTest extends TestCase
{
    /** A scratch directory holding the fake Omeka S root (`omeka/`) and the file sources. */
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp_applier_' . uniqid();
        mkdir($this->dir . '/omeka', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /** @param array<string,string> $entries Archive path => content */
    private function zip(string $name, array $entries): string
    {
        $path = $this->dir . '/' . $name;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return $path;
    }

    /** Run the files phase (only) against the scratch Omeka S root. */
    private function applyFiles(array $files, bool $dryRun = false): void
    {
        $command = $this->createMock(AbstractCommand::class);
        $command->method('resolveOmekaPath')->willReturn($this->dir . '/omeka');
        (new BlueprintApplier($command, $dryRun))->applyModulesAndThemes(new Blueprint(['files' => $files]));
    }

    public function testCopiesAFileAndCreatesMissingDirectories(): void
    {
        $source = $this->write('cleanurl.config.php', '<?php return [];');

        $this->applyFiles([['source' => $source, 'destination' => 'config/sub/cleanurl.config.php']]);

        $this->assertStringEqualsFile($this->dir . '/omeka/config/sub/cleanurl.config.php', '<?php return [];');
    }

    public function testOverwritesAnExistingFile(): void
    {
        mkdir($this->dir . '/omeka/config');
        file_put_contents($this->dir . '/omeka/config/a.php', 'old');
        $source = $this->write('a.php', 'new');

        $this->applyFiles([['source' => $source, 'destination' => 'config/a.php']]);

        $this->assertStringEqualsFile($this->dir . '/omeka/config/a.php', 'new');
    }

    public function testExtractStripsASingleTopLevelDirectory(): void
    {
        $source = $this->zip('assets.zip', ['assets/css/site.css' => 'css', 'assets/logo.svg' => 'svg']);

        $this->applyFiles([['source' => $source, 'destination' => 'files/asset', 'extract' => true]]);

        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/css/site.css', 'css');
        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/logo.svg', 'svg');
        $this->assertDirectoryDoesNotExist($this->dir . '/omeka/files/asset/assets');
    }

    public function testExtractKeepsAnArchiveWithSeveralTopLevelEntries(): void
    {
        $source = $this->zip('assets.zip', ['css/site.css' => 'css', 'logo.svg' => 'svg']);

        $this->applyFiles([['source' => $source, 'destination' => 'files/asset', 'extract' => true]]);

        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/css/site.css', 'css');
        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/logo.svg', 'svg');
    }

    public function testDryRunWritesNothing(): void
    {
        $file = $this->write('a.php', 'x');
        $archive = $this->zip('assets.zip', ['logo.svg' => 'svg']);

        $this->applyFiles([
            ['source' => $file, 'destination' => 'config/a.php'],
            ['source' => $archive, 'destination' => 'files/asset', 'extract' => true],
        ], true);

        $this->assertSame(['.', '..'], scandir($this->dir . '/omeka'));
    }

    public function testAcceptsADestinationThatOnlyStartsWithDots(): void
    {
        $source = $this->write('a.txt', 'x');

        $this->applyFiles([['source' => $source, 'destination' => 'files/..hidden']]);

        $this->assertFileExists($this->dir . '/omeka/files/..hidden');
    }

    public function testRejectsADestinationOutsideTheOmekaRoot(): void
    {
        $source = $this->write('a.txt', 'x');
        foreach (['', '/etc/passwd', '../x', 'config/../../x', 'config\\..\\x', 'C:/x', 'files/..'] as $unsafe) {
            try {
                $this->applyFiles([['source' => $source, 'destination' => $unsafe]]);
                $this->fail("accepted unsafe destination '{$unsafe}'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFileDoesNotExist($this->dir . '/x');
    }

    public function testRejectsAnUnsafeDestinationEvenInADryRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->applyFiles([['source' => 'a.txt', 'destination' => '../x']], true);
    }

    // ── add-on download arguments (asserted through the dry-run report) ──────────────────────────

    /**
     * Dry-run the modules and themes phases and return the reported lines.
     *
     * @return string[]
     */
    private function dryRunAddons(array $blueprint, bool $update = false): array
    {
        $notes = [];
        $command = $this->createMock(AbstractCommand::class);
        $command->method('resolveOmekaPath')->willReturn($this->dir . '/omeka');
        $command->method('note')->willReturnCallback(function (string $message) use (&$notes) {
            $notes[] = $message;
        });
        (new BlueprintApplier($command, true, $update))->applyModulesAndThemes(new Blueprint($blueprint));
        return $notes;
    }

    public function testAddonWithoutSourceResolvesByNameAndVersion(): void
    {
        $notes = $this->dryRunAddons([
            'modules' => ['Common', ['name' => 'Log', 'version' => '3.4.30']],
            'themes' => [['name' => 'freedom', 'version' => '1.0.0']],
        ]);

        $this->assertContains("would download module 'Common' (Common)", $notes);
        $this->assertContains("would download module 'Log' (Log:3.4.30)", $notes);
        $this->assertContains("would download theme 'freedom' (freedom:1.0.0)", $notes);
    }

    public function testAddonSourceIsPassedThroughAndAZipUrlWinsOverTheVersion(): void
    {
        $zip = 'https://example.org/Mapping-2.1.0.zip';
        $notes = $this->dryRunAddons(['modules' => [
            ['name' => 'Mapping', 'source' => $zip, 'version' => '2.0.0'],
            ['name' => 'X', 'source' => 'gh:owner/repo'],
        ]]);

        $this->assertContains("would download module 'Mapping' ({$zip})", $notes);
        $this->assertContains("would download module 'X' (gh:owner/repo)", $notes);
    }

    public function testVersionSelectsTheTagOfAGitSourceUnlessItHasARef(): void
    {
        $notes = $this->dryRunAddons(['modules' => [
            ['name' => 'A', 'source' => 'gh:owner/a', 'version' => '1.2.0'],
            ['name' => 'B', 'source' => 'https://example.org/owner/b.git', 'version' => '1.2.0'],
            ['name' => 'C', 'source' => 'gh:owner/c#main', 'version' => '1.2.0'],
        ]]);

        $this->assertContains("would download module 'A' (gh:owner/a#1.2.0)", $notes);
        $this->assertContains("would download module 'B' (https://example.org/owner/b.git#1.2.0)", $notes);
        $this->assertContains("would download module 'C' (gh:owner/c#main)", $notes);
    }

    public function testAddonOnDiskWithoutSourceIsUsedAsIsUnlessUpdating(): void
    {
        mkdir($this->dir . '/omeka/themes/default', 0777, true);
        $blueprint = ['themes' => ['default']];

        $this->assertContains('default: already present, nothing to download', $this->dryRunAddons($blueprint));
        $this->assertContains("would download theme 'default' (default)", $this->dryRunAddons($blueprint, true));
    }

    public function testAddonOnDiskWithASourceIsUsedAsIs(): void
    {
        // e.g. a module mounted for development, whose entry has a source for other consumers
        mkdir($this->dir . '/omeka/modules/Dev/config', 0777, true);
        file_put_contents($this->dir . '/omeka/modules/Dev/config/module.ini', "[info]\nversion = \"1.2.0\"\n");
        mkdir($this->dir . '/omeka/themes/dev', 0777, true);

        $notes = $this->dryRunAddons([
            'modules' => [['name' => 'Dev', 'source' => 'https://example.org/Dev-1.2.0.zip', 'version' => '1.2.0']],
            'themes' => [['name' => 'dev', 'source' => 'gh:owner/dev']],
        ]);

        $this->assertContains('Dev: already present, nothing to download', $notes);
        $this->assertContains('dev: already present, nothing to download', $notes);
    }

    public function testAddonOnDiskIsDownloadedAgainWhenThePinnedVersionDiffers(): void
    {
        mkdir($this->dir . '/omeka/modules/Dev/config', 0777, true);
        file_put_contents($this->dir . '/omeka/modules/Dev/config/module.ini', "[info]\nversion = \"1.2.0\"\n");
        $zip = 'https://example.org/Dev-1.3.0.zip';

        $notes = $this->dryRunAddons(['modules' => [['name' => 'Dev', 'source' => $zip, 'version' => '1.3.0']]]);

        $this->assertContains("would download module 'Dev' ({$zip})", $notes);
    }
}
