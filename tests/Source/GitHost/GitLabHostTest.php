<?php
namespace Tests\Source\GitHost;

use OSC\Source\GitHost\GitLabHost;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Addon\GitRef;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitLabHost::class)]
#[UsesClass(GitPathSegments::class)]
#[UsesClass(GitRef::class)]
class GitLabHostTest extends TestCase
{
    private GitLabHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new GitLabHost();
    }

    public function testSupportsGitlabComBlobUrl(): void
    {
        $this->assertNotNull($this->host->rawUrl('https://gitlab.com/group/project/-/blob/main/a.jsonc'));
    }

    public function testSupportsSelfHostedBlobUrl(): void
    {
        // recognized by the /-/blob/ path pattern, so any host works
        $this->assertNotNull($this->host->rawUrl('https://gitlab.example.org/group/project/-/blob/main/a.jsonc'));
    }

    public function testSupportsShortScheme(): void
    {
        $this->assertNotNull($this->host->rawUrl('gl:group/project@main:a.jsonc'));
    }

    public function testDoesNotSupportGitHubBlobUrl(): void
    {
        $this->assertNull($this->host->rawUrl('https://github.com/owner/repo/blob/main/a.jsonc'));
    }

    public function testDoesNotSupportPlainGitlabUrl(): void
    {
        $this->assertNull($this->host->rawUrl('https://gitlab.com/group/project'));
    }

    public function testConvertsSelfHostedBlobToRaw(): void
    {
        $this->assertSame(
            'https://gitlab.example.org/group/project/-/raw/main/a.jsonc',
            $this->host->rawUrl('https://gitlab.example.org/group/project/-/blob/main/a.jsonc')
        );
    }

    public function testShortSchemeWithRefToRaw(): void
    {
        $this->assertSame(
            'https://gitlab.com/group/project/-/raw/dev/a.jsonc',
            $this->host->rawUrl('gl:group/project@dev:a.jsonc')
        );
    }

    public function testShortSchemeSupportsNestedGroups(): void
    {
        $this->assertSame(
            'https://gitlab.com/group/subgroup/project/-/raw/main/dir/a.jsonc',
            $this->host->rawUrl('gl:group/subgroup/project@main:dir/a.jsonc')
        );
    }

    public function testShortSchemeWithoutRefDefaultsToHead(): void
    {
        $this->assertSame(
            'https://gitlab.com/group/project/-/raw/HEAD/a.jsonc',
            $this->host->rawUrl('gl:group/project:a.jsonc')
        );
    }

    /** @return array<string,array{0:string}> */
    public static function escapingReferences(): array
    {
        return [
            'gl:trusted/../attacker/repo:f.php' => ['gl:trusted/../attacker/repo:f.php'],
            'gl:g/r@v1:../x.php' => ['gl:g/r@v1:../x.php'],
            'gl:g/./r:f.php' => ['gl:g/./r:f.php'],
            'gl:g/r@--x:f.php' => ['gl:g/r@--x:f.php'],
            'https://gitlab.com/g/../h/-/blob/main/a.php' => ['https://gitlab.com/g/../h/-/blob/main/a.php'],
            'https://gitlab.com/g/r/-/blob/main/../../x.php' => ['https://gitlab.com/g/r/-/blob/main/../../x.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escapingReferences')]
    public function testRejectsAReferenceThatLeavesTheRepository(string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->host->rawUrl($reference);
    }
}
