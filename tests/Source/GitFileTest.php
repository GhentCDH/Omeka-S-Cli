<?php
namespace Tests\Source;

use InvalidArgumentException;
use OSC\Addon\GitRef;
use OSC\Helper\UrlCredentials;
use OSC\Source\GitFile;
use OSC\Source\GitHost\GitHubHost;
use OSC\Source\GitHost\GitLabHost;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Source\HttpUrl;
use OSC\Source\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitFile::class)]
#[CoversClass(GitHubHost::class)]
#[CoversClass(GitLabHost::class)]
#[CoversClass(GitPathSegments::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(HttpUrl::class)]
#[UsesClass(Location::class)]
#[UsesClass(UrlCredentials::class)]
class GitFileTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> written → raw URL */
    public static function gitFiles(): array
    {
        return [
            'gh, no ref'      => ['gh:o/r:dist/a.zip', 'https://raw.githubusercontent.com/o/r/HEAD/dist/a.zip'],
            'gh with ref'     => ['gh:o/r@v1:a.json', 'https://raw.githubusercontent.com/o/r/v1/a.json'],
            'github blob'     => ['https://github.com/o/r/blob/main/a.json', 'https://raw.githubusercontent.com/o/r/main/a.json'],
            'gl nested'       => ['gl:g/sub/r@v2:a.json', 'https://gitlab.com/g/sub/r/-/raw/v2/a.json'],
            'self-hosted blob'=> ['https://gitlab.example.org/g/r/-/blob/main/a.json', 'https://gitlab.example.org/g/r/-/raw/main/a.json'],
        ];
    }

    #[DataProvider('gitFiles')]
    public function testRawUrl(string $written, string $raw): void
    {
        $url = GitFile::rawUrl($written);
        $this->assertInstanceOf(HttpUrl::class, $url);
        $this->assertSame($raw, $url->value());
        $this->assertSame($written, $url->original());
    }

    public function testNotAGitFile(): void
    {
        $this->assertNull(GitFile::rawUrl('https://example.org/a.json'));
        $this->assertNull(GitFile::rawUrl('./a.json'));
    }

    /** @return array<string,array{0:string}> */
    public static function escaping(): array
    {
        return [
            'gh owner dot dot' => ['gh:trusted/..@x:evil.php'],
            'gl namespace'     => ['gl:trusted/../attacker/repo:f'],
            'gh path'          => ['gh:o/r@v1:../../x'],
        ];
    }

    #[DataProvider('escaping')]
    public function testLeavesGitRepository(string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitFile::rawUrl($written);
    }

    /** @return array<string,array{0:string}> */
    public static function encodedRefs(): array
    {
        return ['gh' => ['gh:o/r@%2e%2e:x'], 'gl' => ['gl:g/r@%2e%2e:x']];
    }

    #[DataProvider('encodedRefs')]
    public function testEncodedRefIsChecked(string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitFile::rawUrl($written);
    }

    public function testBlobOnlyInThePath(): void
    {
        $this->assertNull(GitFile::rawUrl('https://host.example/a?x=/-/blob/y'));
    }

    public function testBlobUrlAnyCase(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/o/r/v1/M.zip',
            GitFile::rawUrl('HTTPS://GitHub.com/o/r/blob/v1/M.zip')?->value()
        );
        $this->assertSame(
            'https://gitlab.example.org/g/r/-/raw/main/a.json',
            GitFile::rawUrl('HTTPS://GitLab.Example.org/g/r/-/blob/main/a.json')?->value()
        );
    }
}
