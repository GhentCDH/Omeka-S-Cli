<?php
namespace Tests\Helper;

use Ahc\Cli\Exception\InvalidArgumentException;
use Exception;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\TestCase;

class ResourceFetcherTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary test file
        $this->testFile = sys_get_temp_dir() . '/test_resource_fetcher_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'Test content');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test file
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testDetectTypeFile(): void
    {
        $this->assertEquals('file', ResourceFetcher::detectType('/path/to/file.txt'));
        $this->assertEquals('file', ResourceFetcher::detectType('relative/path/file.txt'));
    }

    public function testDetectTypeUrl(): void
    {
        $this->assertEquals('url', ResourceFetcher::detectType('https://example.org/file.txt'));
        $this->assertEquals('url', ResourceFetcher::detectType('http://example.org/file.txt'));
    }

    public function testIsFile(): void
    {
        $this->assertTrue(ResourceFetcher::isFile('/path/to/file.txt'));
        $this->assertFalse(ResourceFetcher::isFile('https://example.org/file.txt'));
    }

    public function testIsUrl(): void
    {
        $this->assertTrue(ResourceFetcher::isUrl('https://example.org/file.txt'));
        $this->assertFalse(ResourceFetcher::isUrl('/path/to/file.txt'));
    }

    public function testFetchFromFile(): void
    {
        $content = ResourceFetcher::fetch($this->testFile);
        $this->assertEquals('Test content', $content);
    }

    public function testFetchAcceptsAHeadersArgument(): void
    {
        // headers apply to URL sources only; a file source must accept and ignore them
        $content = ResourceFetcher::fetch($this->testFile, ['Accept: text/csv']);
        $this->assertEquals('Test content', $content);
    }

    public function testFetchFromNonExistentFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');

        ResourceFetcher::fetch('/non/existent/file.txt');
    }

    public function testValidateExistingFile(): void
    {
        $this->assertTrue(ResourceFetcher::validate($this->testFile));
    }

    public function testValidateNonExistentFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');

        ResourceFetcher::validate('/non/existent/file.txt');
    }

    public function testValidateUrl(): void
    {
        $this->assertTrue(ResourceFetcher::validate('https://example.org/file.txt'));
    }

    public function testValidateInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL');

        ResourceFetcher::validate('not-a-valid-url');
    }

    public function testFetchFromUnreachableUrlThrowsCatchableException(): void
    {
        // the message depends on the transport (cURL or http stream wrapper)
        $this->expectException(Exception::class);

        // Must throw a plain exception, without emitting a PHP warning
        ResourceFetcher::fetch('http://127.0.0.1:1/nope');
    }

    public function testFetchWithStreamWrapperFromUnreachableUrlThrowsCatchableException(): void
    {
        $fetch = new \ReflectionMethod(ResourceFetcher::class, 'fetchWithStreamWrapper');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to fetch URL: http://127.0.0.1:1/nope');

        // Must throw a plain exception, without emitting a PHP warning
        $fetch->invoke(null, 'http://127.0.0.1:1/nope');
    }
}

