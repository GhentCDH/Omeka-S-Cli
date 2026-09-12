<?php
namespace Tests\Helper\Reference;

use OSC\Helper\Reference\GitHubProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubProvider::class)]
class GitHubProviderTest extends TestCase
{
    private GitHubProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new GitHubProvider();
    }

    public function testSupportsBlobUrl(): void
    {
        $this->assertTrue($this->provider->supports('https://github.com/owner/repo/blob/main/dir/site.jsonc'));
    }

    public function testSupportsShortSchemeWithRef(): void
    {
        $this->assertTrue($this->provider->supports('gh:owner/repo@main:dir/site.jsonc'));
    }

    public function testSupportsShortSchemeWithoutRef(): void
    {
        $this->assertTrue($this->provider->supports('gh:owner/repo:dir/site.jsonc'));
    }

    public function testDoesNotSupportRepoFormWithoutPath(): void
    {
        // this is the existing module-download form, not a file reference
        $this->assertFalse($this->provider->supports('gh:owner/repo#main'));
        $this->assertFalse($this->provider->supports('gh:owner/repo'));
    }

    public function testDoesNotSupportRawUrl(): void
    {
        // already raw; handled as a plain URL by the resolver
        $this->assertFalse($this->provider->supports('https://raw.githubusercontent.com/owner/repo/main/a.jsonc'));
    }

    public function testConvertsBlobUrlToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/dir/site.jsonc',
            $this->provider->toRawUrl('https://github.com/owner/repo/blob/main/dir/site.jsonc')
        );
    }

    public function testStripsFragmentFromBlobUrl(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/a.jsonc',
            $this->provider->toRawUrl('https://github.com/owner/repo/blob/main/a.jsonc#L5')
        );
    }

    public function testShortSchemeWithRefToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/dev/dir/site.jsonc',
            $this->provider->toRawUrl('gh:owner/repo@dev:dir/site.jsonc')
        );
    }

    public function testShortSchemeWithoutRefDefaultsToHead(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/HEAD/dir/site.jsonc',
            $this->provider->toRawUrl('gh:owner/repo:dir/site.jsonc')
        );
    }
}
