<?php
namespace Tests\Source;

use InvalidArgumentException;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\UrlCredentials;
use OSC\Source\HttpUrl;
use OSC\Source\LocalPath;
use OSC\Source\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Location::class)]
#[CoversClass(LocalPath::class)]
#[CoversClass(HttpUrl::class)]
#[UsesClass(ResourceFetcher::class)]
#[UsesClass(UrlCredentials::class)]
#[UsesClass(Path::class)]
class LocationTest extends TestCase
{
    public function testFromResolvedPath(): void
    {
        $location = Location::fromResolved('/srv/a b.zip');
        $this->assertInstanceOf(LocalPath::class, $location);
        $this->assertSame('/srv/a b.zip', $location->value());
        $this->assertFalse($location->isRemote());
        $this->assertNull($location->original());
    }

    public function testFromResolvedUrl(): void
    {
        $location = Location::fromResolved('HTTPS://example.org/a.zip');
        $this->assertInstanceOf(HttpUrl::class, $location);
        $this->assertSame('https://example.org/a.zip', $location->value());
        $this->assertTrue($location->isRemote());
        $this->assertNull($location->original());
    }

    /** @return array<string,array{0:string}> */
    public static function unresolved(): array
    {
        return [
            'dot relative' => ['./a.zip'],
            'relative'     => ['a.zip'],
            'gh shortcut'  => ['gh:o/r:f'],
            'other scheme' => ['php://filter'],
            'no host'      => ['https://'],
            'empty'        => [''],
        ];
    }

    #[DataProvider('unresolved')]
    public function testFromResolvedRejects(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Location::fromResolved($value);
    }

    public function testDisplayRedacts(): void
    {
        $this->assertSame('https://***@example.org/a', HttpUrl::fromUrl('https://u:p@example.org/a')->display());
    }

    public function testDisplayShowsTheOriginal(): void
    {
        $this->assertSame(
            "'gh:o/r@v1:a.json' (https://raw.githubusercontent.com/o/r/v1/a.json)",
            HttpUrl::fromUrl('https://raw.githubusercontent.com/o/r/v1/a.json', 'gh:o/r@v1:a.json')->display()
        );
        $this->assertSame("'./a%20b.jpg' (/w/cfg/a b.jpg)", LocalPath::fromAbsolute('/w/cfg/a b.jpg', './a%20b.jpg')->display());
        $this->assertSame('https://example.org/x.zip', HttpUrl::fromUrl('https://example.org/x.zip', 'https://example.org/x.zip')->display());
    }

    public function testLocalPathMustBeAbsolute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LocalPath::fromAbsolute('a.json');
    }

    public function testFetcherTakesALocation(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'loc');
        file_put_contents($tmp, 'content');
        try {
            $this->assertSame('content', ResourceFetcher::fetch(LocalPath::fromAbsolute($tmp)));
        } finally {
            unlink($tmp);
        }
    }

    public function testFetchErrorNamesTheOriginal(): void
    {
        try {
            ResourceFetcher::fetch(LocalPath::fromAbsolute('/nonexistent/x.json'));
            $this->fail('no exception');
        } catch (Throwable $e) {
            $this->assertStringContainsString('/nonexistent/x.json', $e->getMessage());
        }
        try {
            ResourceFetcher::fetch(HttpUrl::fromUrl('http://127.0.0.1:1/x', 'gh:o/r:x'));
            $this->fail('no exception');
        } catch (Throwable $e) {
            $this->assertStringContainsString("'gh:o/r:x'", $e->getMessage());
        }
    }

    public function testTheFolderOfALocalPath(): void
    {
        $this->assertSame('/srv/bp', LocalPath::fromAbsolute('/srv/bp/blueprint.json')->folder());
    }

    /** @return array<string,array{0:string}> */
    public static function unnormalizedUrls(): array
    {
        return [
            'plain'            => ['https://example.org/bp/blueprint.json'],
            'uppercase scheme' => ['HTTPS://example.org/bp/blueprint.json'],
            'uppercase host'   => ['https://Example.ORG/bp/blueprint.json'],
            'default port'     => ['https://example.org:443/bp/blueprint.json'],
            'dot segment'      => ['https://example.org/bp/./blueprint.json'],
            'query, fragment'  => ['https://example.org/bp/blueprint.json?a=1#x'],
        ];
    }

    #[DataProvider('unnormalizedUrls')]
    public function testTheFolderOfAUrlIsNormalized(string $url): void
    {
        $this->assertSame('https://example.org/bp/', HttpUrl::fromUrl($url)->folder());
    }
}
