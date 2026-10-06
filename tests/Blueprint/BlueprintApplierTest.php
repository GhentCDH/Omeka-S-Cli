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
}
