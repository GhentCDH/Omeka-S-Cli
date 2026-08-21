<?php

namespace OSC\Tests\Database;

use Exception;
use OSC\Database\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamFactory::class)]
class StreamFactoryTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function path(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/osc-stream-' . uniqid() . $suffix;
        $this->files[] = $path;

        return $path;
    }

    public function testWritesAndReadsAPlainFile(): void
    {
        $path = $this->path('.sql');

        $handle = StreamFactory::openWrite($path, false);
        fwrite($handle, 'SELECT 1;');
        fclose($handle);

        $this->assertFalse(StreamFactory::isGzip($path));

        $handle = StreamFactory::openRead($path);
        $this->assertSame('SELECT 1;', stream_get_contents($handle));
        fclose($handle);
    }

    public function testWritesRealGzip(): void
    {
        $path = $this->path('.sql.gz');

        $handle = StreamFactory::openWrite($path, true);
        fwrite($handle, 'SELECT 1;');
        fclose($handle);

        $this->assertSame("\x1f\x8b", substr((string) file_get_contents($path), 0, 2));
        $this->assertSame('SELECT 1;', gzdecode((string) file_get_contents($path)));
        $this->assertTrue(StreamFactory::isGzip($path));
    }

    public function testCompressionIsDetectedFromTheContentNotTheName(): void
    {
        // A gzipped dump that was renamed to .sql must still be read as gzip.
        $path = $this->path('.sql');
        file_put_contents($path, (string) gzencode('SELECT 1;'));

        $handle = StreamFactory::openRead($path);
        $this->assertSame('SELECT 1;', stream_get_contents($handle));
        fclose($handle);
    }

    public function testGzipIsImpliedByTheFileName(): void
    {
        $this->assertTrue(StreamFactory::wantsGzip('/tmp/dump.SQL.GZ'));
        $this->assertFalse(StreamFactory::wantsGzip('/tmp/dump.sql'));
    }

    public function testReadingAMissingFileFails(): void
    {
        $this->expectException(Exception::class);

        StreamFactory::openRead('/tmp/osc-does-not-exist-' . uniqid());
    }
}
