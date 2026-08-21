<?php
namespace Tests\Helper;

use InvalidArgumentException;
use OSC\Helper\Path;
use PHPUnit\Framework\TestCase;

class FileUtilsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file_utils_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
    }

    private function removeDir(string $path): void
    {
        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot()) continue;
            $item->isDir() ? $this->removeDir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    public function testFindSubpathDirectMatch(): void
    {
        // target is directly inside baseFolder
        mkdir($this->tempDir . '/target');

        $result = Path::findSubpath($this->tempDir, 'target');
        $this->assertEquals(realpath($this->tempDir), $result);
    }

    public function testFindSubpathNestedOneLevel(): void
    {
        // target is one level deep
        mkdir($this->tempDir . '/level1');
        mkdir($this->tempDir . '/level1/target');

        $result = Path::findSubpath($this->tempDir, 'target');
        $this->assertEquals(realpath($this->tempDir . '/level1'), $result);
    }

    public function testFindSubpathNestedTwoLevels(): void
    {
        // target is two levels deep — would fail without RecursiveIteratorIterator
        mkdir($this->tempDir . '/level1/level2', 0700, true);
        mkdir($this->tempDir . '/level1/level2/target');

        $result = Path::findSubpath($this->tempDir, 'target');
        $this->assertEquals(realpath($this->tempDir . '/level1/level2'), $result);
    }

    public function testFindSubpathFileTarget(): void
    {
        // target can also be a file
        mkdir($this->tempDir . '/level1/level2', 0700, true);
        file_put_contents($this->tempDir . '/level1/level2/config.json', '{}');

        $result = Path::findSubpath($this->tempDir, 'config.json');
        $this->assertEquals(realpath($this->tempDir . '/level1/level2'), $result);
    }

    public function testFindSubpathReturnsNullWhenNotFound(): void
    {
        mkdir($this->tempDir . '/level1');

        $result = Path::findSubpath($this->tempDir, 'nonexistent');
        $this->assertNull($result);
    }

    public function testFindSubpathThrowsOnInvalidBaseFolder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Path::findSubpath('/nonexistent/path', 'target');
    }
}
