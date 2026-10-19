<?php
namespace Tests\Helper;

use Ahc\Cli\Exception\InvalidArgumentException;
use Exception;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceFetcher::class)]
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

        // an http(s) scheme makes it a URL; a string without one is treated as a file path
        ResourceFetcher::validate('https://not a valid url');
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

    public function testDownloadCopiesALocalFileAndHasNoContentType(): void
    {
        $target = $this->testFile . '.copy';
        try {
            $this->assertNull(ResourceFetcher::download($this->testFile, $target));
            $this->assertSame('Test content', file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }

    public function testDownloadOfAMissingFileFails(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        ResourceFetcher::download($this->testFile . '.missing', $this->testFile . '.x');
    }

    public function testStreamWrapperDownloadCopiesToTheTarget(): void
    {
        $download = new \ReflectionMethod(ResourceFetcher::class, 'downloadWithStreamWrapper');
        $target = $this->testFile . '.copy';
        try {
            $download->invoke(null, 'file://' . $this->testFile, $target, []);
            $this->assertSame('Test content', file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }

    public function testStreamWrapperDownloadOfAnUnreachableUrlFailsAndLeavesNoTarget(): void
    {
        $download = new \ReflectionMethod(ResourceFetcher::class, 'downloadWithStreamWrapper');
        $target = $this->testFile . '.x';
        touch($target); // e.g. the temp file a caller created for the download
        try {
            // must throw a plain exception, without emitting a PHP warning
            $download->invoke(null, 'http://127.0.0.1:1/nope', $target, []);
            $this->fail('an unreachable URL must fail');
        } catch (Exception $e) {
            // the reason only (its wording is the OS's), without PHP's "fopen(<url>): Failed to open stream:" prefix
            $this->assertStringStartsWith("Failed to download 'http://127.0.0.1:1/nope': ", $e->getMessage());
            $this->assertStringNotContainsString('fopen(', $e->getMessage());
            $this->assertSame(rtrim($e->getMessage()), $e->getMessage());
        }
        $this->assertFileDoesNotExist($target);
    }

    public function testOnlyTheFinalResponseHeadersCount(): void
    {
        $finalHeaders = new \ReflectionMethod(ResourceFetcher::class, 'finalHeaders');

        // a redirect page's Content-Type must not survive into a final response without one
        $this->assertSame([], $finalHeaders->invoke(null, [
            'HTTP/1.1 302 Found', 'Content-Type: text/html', 'Location: /x.ttl',
            'HTTP/1.1 200 OK',
        ]));
        $this->assertSame(['content-type' => 'text/turtle; charset=utf-8', 'content-length' => '42'], $finalHeaders->invoke(null, [
            'HTTP/1.1 301 Moved Permanently', 'Content-Type: text/html',
            'HTTP/1.1 200 OK', 'Content-Type: text/turtle; charset=utf-8', 'Content-Length: 42',
        ]));
    }

    public function testADownloadIsAbortedWhenItStallsNotWhenItIsSlow(): void
    {
        $options = (new \ReflectionMethod(ResourceFetcher::class, 'curlDownloadOptions'))->invoke(null);

        // no limit on the whole transfer: a large release on a slow link must still arrive
        $this->assertArrayNotHasKey(CURLOPT_TIMEOUT, $options);
        $this->assertSame(30, $options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(1024, $options[CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(60, $options[CURLOPT_LOW_SPEED_TIME]);
    }

    public function testATruncatedResponseIsDetected(): void
    {
        $truncation = new \ReflectionMethod(ResourceFetcher::class, 'truncation');

        $this->assertSame('the connection closed after 100 of 100000 bytes', $truncation->invoke(null, 100, ['content-length' => '100000']));
        $this->assertNull($truncation->invoke(null, 100000, ['content-length' => '100000']));
        // without a length, or for an encoded body whose length is not the decoded size, nothing can be told
        $this->assertNull($truncation->invoke(null, 100, []));
        $this->assertNull($truncation->invoke(null, 100, ['content-length' => '100000', 'content-encoding' => 'gzip']));
        $this->assertNull($truncation->invoke(null, 100, ['content-length' => 'n/a']));
    }
}
