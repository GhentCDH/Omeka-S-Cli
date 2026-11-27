<?php
namespace Tests\Source;

use InvalidArgumentException;
use OSC\Addon\GitRef;
use OSC\Helper\UrlCredentials;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Source\GitRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitRepository::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(UrlCredentials::class)]
#[UsesClass(GitPathSegments::class)]
class GitRepositoryTest extends TestCase
{
    /** @return array<string,array{0:string,1:?string,2:string,3:?string}> written, version → clone URL, ref */
    public static function repositories(): array
    {
        return [
            'gh, version is ref'  => ['gh:owner/a', '1.2.0', 'https://github.com/owner/a.git', '1.2.0'],
            'gh ref wins'         => ['gh:owner/c#main', '1.2.0', 'https://github.com/owner/c.git', 'main'],
            'github url'          => ['https://github.com/owner/d', '1.2.0', 'https://github.com/owner/d.git', '1.2.0'],
            'github url slash'    => ['https://github.com/o/d/', null, 'https://github.com/o/d.git', null],
            'github url ref wins' => ['https://github.com/owner/e#main', '1.2.0', 'https://github.com/owner/e.git', 'main'],
            'dot git url'         => ['https://example.org/owner/b.git', '1.2.0', 'https://example.org/owner/b.git', '1.2.0'],
            'dot git url nested'  => ['https://gitlab.com/g/sub/g.git#v2', null, 'https://gitlab.com/g/sub/g.git', 'v2'],
            'dot git over http'   => ['http://example.org/h.git', null, 'http://example.org/h.git', null],
            'uppercase scheme'    => ['HTTPS://example.org/u.git', null, 'https://example.org/u.git', null],
            'ssh'                 => ['git@gitlab.com:g/h.git', null, 'git@gitlab.com:g/h.git', null],
            'ref with a slash'    => ['gh:o/r#feature/x', null, 'https://github.com/o/r.git', 'feature/x'],
            'gh with dot git'     => ['gh:o/r.git', null, 'https://github.com/o/r.git', null],
            'github url dot git'  => ['https://github.com/o/r.git', null, 'https://github.com/o/r.git', null],
            'github dot git slash' => ['https://github.com/o/r.git/', null, 'https://github.com/o/r.git', null],
        ];
    }

    #[DataProvider('repositories')]
    public function testParse(string $written, ?string $version, string $cloneUrl, ?string $ref): void
    {
        $repository = GitRepository::parse($written);
        $this->assertSame($written, $repository->original());
        if ($repository->ref() === null) {
            $repository = $repository->withRef($version);
        }
        $this->assertSame($cloneUrl, $repository->cloneUrl());
        $this->assertSame($ref, $repository->ref());
        $this->assertSame($repository->value(), GitRepository::parse($repository->value())->value());
    }

    /** @return array<string,array{0:string}> */
    public static function invalid(): array
    {
        return [
            'gh owner dot dot' => ['gh:o/..'],
            'github dot dot'   => ['https://github.com/o/..'],
            'option ref'       => ['gh:o/r#-x'],
            'not a repository' => ['https://example.org/a.zip'],
            // spec fixtures/resolution/repository-containment: `..`, also encoded, as written
            'dot git encoded'  => ['https://github.com/o/%2e%2e/other/r.git'],
            'dot git dot dot'  => ['https://example.org/a/../r.git'],
            'ssh dot dot'      => ['git@gitlab.com:g/../r.git'],
            'gh encoded owner' => ['gh:%2e%2e/r'],
            'encoded ref'      => ['gh:o/r#%2e%2e'],
        ];
    }

    #[DataProvider('invalid')]
    public function testRejects(string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitRepository::parse($written);
    }

    public function testOptionRefViaWithRef(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitRepository::parse('gh:o/r')->withRef('--upload-pack=x');
    }

    public function testDisplayShowsTheOriginal(): void
    {
        $this->assertSame("'gh:o/m#v2.0' (https://github.com/o/m.git#v2.0)", GitRepository::parse('gh:o/m#v2.0')->display());
        $this->assertSame('https://***@example.org/m.git', GitRepository::parse('https://u:p@example.org/m.git')->display());
        // a ref from another field (a blueprint's version): a canonical URL is shown once, a shortcut with it
        $this->assertSame('https://example.org/m.git#1.2.0', GitRepository::parse('https://example.org/m.git')->withRef('1.2.0')->display());
        $this->assertSame("'gh:o/m' (https://github.com/o/m.git#1.2.0)", GitRepository::parse('gh:o/m')->withRef('1.2.0')->display());
    }
}
