<?php
namespace Tests\Source;

use InvalidArgumentException;
use OSC\Addon\GitRef;
use OSC\Helper\Path;
use OSC\Helper\UrlCredentials;
use OSC\Source\Document;
use OSC\Source\DocumentSourceResolver;
use OSC\Source\GitFile;
use OSC\Source\GitHost\GitHubHost;
use OSC\Source\GitHost\GitLabHost;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Source\HttpUrl;
use OSC\Source\LocalPath;
use OSC\Source\Location;
use OSC\Source\SourceException;
use OSC\Source\SourceForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[UsesClass(DocumentSourceResolver::class)]
#[UsesClass(SourceException::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(GitFile::class)]
#[UsesClass(GitHubHost::class)]
#[UsesClass(GitLabHost::class)]
#[UsesClass(GitPathSegments::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(HttpUrl::class)]
#[UsesClass(LocalPath::class)]
#[UsesClass(Location::class)]
#[UsesClass(Path::class)]
#[UsesClass(UrlCredentials::class)]
class DocumentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = realpath(sys_get_temp_dir()) . '/osc-doc-' . bin2hex(random_bytes(4));
        mkdir("{$this->dir}/cfg", 0777, true);
        mkdir("{$this->dir}/data", 0777, true);
        file_put_contents("{$this->dir}/cfg/c.json", '{}');
        file_put_contents("{$this->dir}/cfg/a b.json", '{}');
        file_put_contents("{$this->dir}/data/x.json", '{}');
    }

    protected function tearDown(): void
    {
        Path::removeFolder($this->dir);
    }

    private function config(?string $root = null): Document
    {
        return Document::open(LocalPath::fromAbsolute("{$this->dir}/cfg/c.json"), $root);
    }

    public function testLocalFileRootIsItsFolder(): void
    {
        $document = $this->config();
        $this->assertSame("{$this->dir}/cfg", $document->root);
        $this->assertSame("{$this->dir}/cfg/c.json", $document->source->value());
        $this->assertFalse($document->isRemote());
    }

    public function testRootOverrideWidens(): void
    {
        $this->assertSame("{$this->dir}/data/x.json", $this->config($this->dir)->resolve('../data/x.json')->value());
    }

    public function testRootOverrideMustBeADirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory');
        $this->config("{$this->dir}/missing");
    }

    public function testRelativeResolvesAgainstTheFile(): void
    {
        $location = $this->config()->resolve('a%20b.json');
        $this->assertInstanceOf(LocalPath::class, $location);
        $this->assertSame("{$this->dir}/cfg/a b.json", $location->value());
    }

    public function testAbsoluteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute path');
        $this->config()->resolve('/etc/hosts');
    }

    public function testEscapeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the root');
        $this->config()->resolve('../data/x.json');
    }

    public function testUnencodedHashIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('%23');
        $this->config()->resolve('a#1.json');
    }

    public function testSymlinkedFolder(): void
    {
        symlink("{$this->dir}/cfg", "{$this->dir}/link");
        $document = Document::open(LocalPath::fromAbsolute("{$this->dir}/link/c.json"), null);
        $this->assertSame("{$this->dir}/cfg/a b.json", realpath($document->resolve('a%20b.json')->value()));
    }

    public function testRemoteFile(): void
    {
        $document = Document::open(HttpUrl::fromUrl('https://example.org/cfg/c.json'), null);
        $this->assertSame('https://example.org/cfg/', $document->root);
        $this->assertTrue($document->isRemote());
        $this->assertSame('https://example.org/cfg/x.json', $document->resolve('x.json')->value());
        try {
            $document->resolve('../y.json');
            $this->fail('should leave the root');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outside the root', $e->getMessage());
            $this->assertStringNotContainsString('--root', $e->getMessage());
        }
    }

    public function testRemoteFileRefusesRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--root only applies to a local file');
        Document::open(HttpUrl::fromUrl('https://example.org/cfg/c.json'), '/tmp');
    }

    public function testCredentialsRedacted(): void
    {
        $document = Document::open(HttpUrl::fromUrl('https://u:p@example.org/cfg/c.json'), null);
        try {
            $document->resolve('../y.json');
            $this->fail('should leave the root');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('https://***@', $e->getMessage());
            $this->assertStringNotContainsString('u:p', $e->getMessage());
        }
    }
}
