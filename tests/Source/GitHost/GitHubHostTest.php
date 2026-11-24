<?php
namespace Tests\Source\GitHost;

use OSC\Source\GitHost\GitHubHost;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Addon\GitRef;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubHost::class)]
#[UsesClass(GitPathSegments::class)]
#[UsesClass(GitRef::class)]
class GitHubHostTest extends TestCase
{
    private GitHubHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new GitHubHost();
    }

    public function testSupportsBlobUrl(): void
    {
        $this->assertNotNull($this->host->rawUrl('https://github.com/owner/repo/blob/main/dir/site.jsonc'));
    }

    public function testSupportsShortSchemeWithRef(): void
    {
        $this->assertNotNull($this->host->rawUrl('gh:owner/repo@main:dir/site.jsonc'));
    }

    public function testSupportsShortSchemeWithoutRef(): void
    {
        $this->assertNotNull($this->host->rawUrl('gh:owner/repo:dir/site.jsonc'));
    }

    public function testDoesNotSupportRepoFormWithoutPath(): void
    {
        // this is the existing module-download form, not a file reference
        $this->assertNull($this->host->rawUrl('gh:owner/repo#main'));
        $this->assertNull($this->host->rawUrl('gh:owner/repo'));
    }

    public function testDoesNotSupportRawUrl(): void
    {
        // already raw; handled as a plain URL by the resolver
        $this->assertNull($this->host->rawUrl('https://raw.githubusercontent.com/owner/repo/main/a.jsonc'));
    }

    public function testConvertsBlobUrlToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/dir/site.jsonc',
            $this->host->rawUrl('https://github.com/owner/repo/blob/main/dir/site.jsonc')
        );
    }

    public function testStripsFragmentFromBlobUrl(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/a.jsonc',
            $this->host->rawUrl('https://github.com/owner/repo/blob/main/a.jsonc#L5')
        );
    }

    public function testShortSchemeWithRefToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/dev/dir/site.jsonc',
            $this->host->rawUrl('gh:owner/repo@dev:dir/site.jsonc')
        );
    }

    public function testShortSchemeWithoutRefDefaultsToHead(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/HEAD/dir/site.jsonc',
            $this->host->rawUrl('gh:owner/repo:dir/site.jsonc')
        );
    }

    /** @return array<string,array{0:string}> */
    public static function escapingReferences(): array
    {
        return [
            'gh:trusted/..@attacker:evil/main/evil.php' => ['gh:trusted/..@attacker:evil/main/evil.php'],
            'gh:o/r@v1:../../other/repo/main/x.php' => ['gh:o/r@v1:../../other/repo/main/x.php'],
            'gh:o/r@v1:a/%2e%2e/%2E%2E/b.php' => ['gh:o/r@v1:a/%2e%2e/%2E%2E/b.php'],
            'gh:o/r@-x:a.php' => ['gh:o/r@-x:a.php'],
            'gh:o/r@v1:a//b.php' => ['gh:o/r@v1:a//b.php'],
            'gh:o/r@v1:a/%2Fb.php' => ['gh:o/r@v1:a/%2Fb.php'],
            'https://github.com/o/../blob/main/a.php' => ['https://github.com/o/../blob/main/a.php'],
            'https://github.com/o/r/blob/main/../../x/y/z.php' => ['https://github.com/o/r/blob/main/../../x/y/z.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escapingReferences')]
    public function testRejectsAReferenceThatLeavesTheRepository(string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->host->rawUrl($reference);
    }
}
