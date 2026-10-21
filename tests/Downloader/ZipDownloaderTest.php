<?php
namespace Tests\Downloader;

use Exception;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(ZipDownloader::class)]
#[UsesClass(Path::class)]
#[UsesClass(ResourceFetcher::class)]
class ZipDownloaderTest extends TestCase
{
    private string $dir;

    /** @var string[] The CLI's temp entries before the test */
    private array $tempBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/zip_downloader_' . uniqid();
        mkdir($this->dir, 0777, true);
        $this->tempBefore = self::tempEntries();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    /** @return string[] The CLI's temp files and folders (tempnam() and createTempFolder() share the prefix) */
    private static function tempEntries(): array
    {
        return glob(sys_get_temp_dir() . '/omeka-s-cli.*') ?: [];
    }

    /** Assert the test left no new CLI temp entry, apart from $except. */
    private function assertNoNewTempEntries(string ...$except): void
    {
        $this->assertSame([], array_values(array_diff(self::tempEntries(), $this->tempBefore, $except)));
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

    public function testDownloadsALocalZip(): void
    {
        $folder = (new ZipDownloader($this->zip(['Foo/config/module.ini' => 'version = "1.0"'])))->download();
        try {
            $this->assertFileExists("{$folder}/Foo/config/module.ini");
            $this->assertNoNewTempEntries($folder);
        } finally {
            Path::removeFolder($folder);
        }
    }

    public function testAUrlDownloadLeavesNoTempFile(): void
    {
        // the download is injected, so the URL path (a temp ZIP, then extract) runs without a network
        $zip = $this->zip(['Foo/config/module.ini' => 'version = "1.0"']);
        $downloader = new ZipDownloader('https://example.org/Foo.zip', function (string $source, string $target) use ($zip): ?string {
            $this->assertSame('https://example.org/Foo.zip', $source);
            copy($zip, $target);
            return 'application/zip';
        });

        $folder = $downloader->download();
        try {
            $this->assertFileExists("{$folder}/Foo/config/module.ini");
            $this->assertNoNewTempEntries($folder);
        } finally {
            Path::removeFolder($folder);
        }
    }

    public function testAnUnsupportedSchemeIsRefused(): void
    {
        try {
            (new ZipDownloader('ftp://example.org/Foo.zip'))->download();
            $this->fail('ftp:// must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Unsupported source 'ftp://example.org/Foo.zip'", $e->getMessage());
        }
        $this->assertNoNewTempEntries();
    }

    public function testALocalZipIsLeftUnchanged(): void
    {
        $path = $this->zip(['Foo/config/module.ini' => 'version = "1.0"']);
        $md5 = md5_file($path);

        Path::removeFolder((new ZipDownloader($path))->download());

        $this->assertFileExists($path);
        $this->assertSame($md5, md5_file($path));
    }

    public function testAMissingLocalZipIsNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Zip file not found: '{$this->dir}/missing.zip'");
        (new ZipDownloader("{$this->dir}/missing.zip"))->download();
    }

    public function testALocalFileThatIsNotAZipIsRefused(): void
    {
        $path = "{$this->dir}/broken.zip";
        file_put_contents($path, 'not a zip');
        try {
            (new ZipDownloader($path))->download();
            $this->fail('a file that is not a ZIP must be refused');
        } catch (Exception $e) {
            $this->assertSame("{$path} is not a ZIP archive", $e->getMessage());
        }
        $this->assertNoNewTempEntries();
    }

    public function testAFailedDownloadLeavesNothingBehind(): void
    {
        try {
            (new ZipDownloader('http://127.0.0.1:1/x.zip'))->download();
            $this->fail('an unreachable URL must fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('http://127.0.0.1:1/x.zip', $e->getMessage());
        }
        $this->assertNoNewTempEntries();
    }

    public function testExtractNamesTheSource(): void
    {
        $path = "{$this->dir}/broken.zip";
        file_put_contents($path, 'not a zip');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('https://example.org/x.zip is not a ZIP archive');
        ZipDownloader::extract($path, 'https://example.org/x.zip');
    }

    public function testExtractRemovesItsFolderWhenUnpackingFails(): void
    {
        if (!method_exists(ZipArchive::class, 'setEncryptionName')) {
            $this->markTestSkipped('libzip has no encryption support');
        }
        $path = "{$this->dir}/encrypted.zip";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('Foo/config/module.ini', 'version = "1.0"');
        $zip->setEncryptionName('Foo/config/module.ini', ZipArchive::EM_AES_256, 'secret');
        $zip->close();

        try {
            ZipDownloader::extract($path);
            $this->fail('an encrypted ZIP must be refused');
        } catch (Exception $e) {
            $this->assertSame("could not unpack {$path}", $e->getMessage());
        }
        $this->assertNoNewTempEntries();
    }
}
