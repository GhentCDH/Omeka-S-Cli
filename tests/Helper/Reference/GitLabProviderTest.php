<?php
namespace Tests\Helper\Reference;

use OSC\Helper\Reference\GitLabProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitLabProvider::class)]
class GitLabProviderTest extends TestCase
{
    private GitLabProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new GitLabProvider();
    }

    public function testSupportsGitlabComBlobUrl(): void
    {
        $this->assertTrue($this->provider->supports('https://gitlab.com/group/project/-/blob/main/a.jsonc'));
    }

    public function testSupportsSelfHostedBlobUrl(): void
    {
        // recognized by the /-/blob/ path pattern, so any host works
        $this->assertTrue($this->provider->supports('https://gitlab.example.org/group/project/-/blob/main/a.jsonc'));
    }

    public function testSupportsShortScheme(): void
    {
        $this->assertTrue($this->provider->supports('gl:group/project@main:a.jsonc'));
    }

    public function testDoesNotSupportGitHubBlobUrl(): void
    {
        $this->assertFalse($this->provider->supports('https://github.com/owner/repo/blob/main/a.jsonc'));
    }

    public function testDoesNotSupportPlainGitlabUrl(): void
    {
        $this->assertFalse($this->provider->supports('https://gitlab.com/group/project'));
    }

    public function testConvertsSelfHostedBlobToRaw(): void
    {
        $this->assertSame(
            'https://gitlab.example.org/group/project/-/raw/main/a.jsonc',
            $this->provider->toRawUrl('https://gitlab.example.org/group/project/-/blob/main/a.jsonc')
        );
    }

    public function testShortSchemeWithRefToRaw(): void
    {
        $this->assertSame(
            'https://gitlab.com/group/project/-/raw/dev/a.jsonc',
            $this->provider->toRawUrl('gl:group/project@dev:a.jsonc')
        );
    }

    public function testShortSchemeSupportsNestedGroups(): void
    {
        $this->assertSame(
            'https://gitlab.com/group/subgroup/project/-/raw/main/dir/a.jsonc',
            $this->provider->toRawUrl('gl:group/subgroup/project@main:dir/a.jsonc')
        );
    }

    public function testShortSchemeWithoutRefDefaultsToHead(): void
    {
        $this->assertSame(
            'https://gitlab.com/group/project/-/raw/HEAD/a.jsonc',
            $this->provider->toRawUrl('gl:group/project:a.jsonc')
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
        $this->provider->toRawUrl($reference);
    }
}
