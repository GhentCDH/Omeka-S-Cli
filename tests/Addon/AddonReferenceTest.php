<?php
namespace Tests\Addon;

use InvalidArgumentException;
use OSC\Addon\AddonReference;
use OSC\Addon\GitRef;
use OSC\Blueprint\SourceForm;
use OSC\Helper\Reference\GitHubProvider;
use OSC\Helper\Reference\GitLabProvider;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\Reference\RepoSegments;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddonReference::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(ReferenceResolver::class)]
#[UsesClass(GitHubProvider::class)]
#[UsesClass(GitLabProvider::class)]
#[UsesClass(RepoSegments::class)]
#[UsesClass(ResourceFetcher::class)]
class AddonReferenceTest extends TestCase
{
    /** @return array<string,array{0:string,1:?string,2:?string,3:string,4:?string,5:?string}> name, source, version → kind, location, version */
    public static function blueprintEntries(): array
    {
        return [
            'name only'            => ['Common', null, null, AddonReference::BY_NAME, null, null],
            'name and version'     => ['Log', null, '3.4.30', AddonReference::BY_NAME, null, '3.4.30'],
            'zip wins over version'=> ['M', 'https://example.org/M-2.1.0.zip', '2.0.0', AddonReference::ZIP_URL, 'https://example.org/M-2.1.0.zip', null],
            'any url is a zip'     => ['F', 'https://example.org/dl?id=5', '1.2.0', AddonReference::ZIP_URL, 'https://example.org/dl?id=5', null],
            'uppercase scheme'     => ['U', 'HTTPS://example.org/U.zip', null, AddonReference::ZIP_URL, 'https://example.org/U.zip', null],
            'archive zip'          => ['Z', 'https://github.com/o/z/archive/refs/tags/v1.zip', null, AddonReference::ZIP_URL, 'https://github.com/o/z/archive/refs/tags/v1.zip', null],
            'zip file'             => ['L', '/bundle/modules/L.zip', '1.0', AddonReference::ZIP_FILE, '/bundle/modules/L.zip', null],
            'zip file any name'    => ['L', '/bundle/modules/L-release', null, AddonReference::ZIP_FILE, '/bundle/modules/L-release', null],
            'gh, version is ref'   => ['A', 'gh:owner/a', '1.2.0', AddonReference::GIT, 'https://github.com/owner/a.git', '1.2.0'],
            'gh ref wins'          => ['C', 'gh:owner/c#main', '1.2.0', AddonReference::GIT, 'https://github.com/owner/c.git', 'main'],
            'github url'           => ['D', 'https://github.com/owner/d', '1.2.0', AddonReference::GIT, 'https://github.com/owner/d.git', '1.2.0'],
            'github url slash'     => ['D', 'https://github.com/o/d/', null, AddonReference::GIT, 'https://github.com/o/d.git', null],
            'github url ref wins'  => ['E', 'https://github.com/owner/e#main', '1.2.0', AddonReference::GIT, 'https://github.com/owner/e.git', 'main'],
            'dot git url'          => ['B', 'https://example.org/owner/b.git', '1.2.0', AddonReference::GIT, 'https://example.org/owner/b.git', '1.2.0'],
            'dot git url nested'   => ['G', 'https://gitlab.com/g/sub/g.git#v2', null, AddonReference::GIT, 'https://gitlab.com/g/sub/g.git', 'v2'],
            'dot git over http'    => ['H', 'http://example.org/h.git', null, AddonReference::GIT, 'http://example.org/h.git', null],
            'ssh'                  => ['S', 'git@gitlab.com:g/h.git', null, AddonReference::GIT, 'git@gitlab.com:g/h.git', null],
            'ref with a slash'     => ['R', 'gh:o/r#feature/x', null, AddonReference::GIT, 'https://github.com/o/r.git', 'feature/x'],
            'file in a repository' => ['F', 'gh:o/f@v1:dist/F.zip', '9.9', AddonReference::ZIP_URL, 'https://raw.githubusercontent.com/o/f/v1/dist/F.zip', null],
        ];
    }

    #[DataProvider('blueprintEntries')]
    public function testFromABlueprintEntry(string $name, ?string $source, ?string $version, string $kind, ?string $location, ?string $expectedVersion): void
    {
        $reference = AddonReference::fromBlueprint($name, $source, $version);
        $this->assertSame($kind, $reference->kind);
        $this->assertSame($location, $reference->location);
        $this->assertSame($expectedVersion, $reference->version);
        $this->assertSame($kind === AddonReference::BY_NAME ? $name : null, $reference->name);
    }

    /** @return array<string,array{0:?string,1:?string}> source, version */
    public static function invalidEntries(): array
    {
        return [
            'id:version source'  => ['CleanUrl:3.4', null],
            'unresolved path'    => ['./zips/M.zip', null],
            'gh owner dot dot'   => ['gh:o/..', null],
            'github dot dot'     => ['https://github.com/o/..', null],
            'escaping file'      => ['gh:trusted/..@x:evil.zip', null],
            'option ref'         => ['gh:o/r#-x', null],
            'option version'     => ['gh:o/r', '--upload-pack=x'],
            'gl repository'      => ['gl:g/r', null],
            'empty'              => ['', null],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function testRejectsAnInvalidSource(?string $source, ?string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        AddonReference::fromBlueprint('X', $source, $version);
    }

    public function testAnInvalidNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AddonReference::byName('CleanUrl:3.4');
    }

    public function testDisplayHidesCredentialsAndShowsTheRef(): void
    {
        $this->assertSame('Common', AddonReference::byName('Common')->display());
        $this->assertSame('Log:3.4.30', AddonReference::byName('Log', '3.4.30')->display());
        $this->assertSame('https://***@example.org/M.zip', AddonReference::fromSource('https://u:p@example.org/M.zip')->display());
        $this->assertSame('https://github.com/o/a.git#v1', AddonReference::fromSource('gh:o/a#v1')->display());
    }

    public function testIsGitAndAddonId(): void
    {
        $this->assertTrue(AddonReference::fromSource('gh:o/a')->isGit());
        $this->assertFalse(AddonReference::fromSource('https://example.org/a.zip')->isGit());
        $this->assertSame('Common', AddonReference::byName('Common', '1.0')->addonId());
        $this->assertNull(AddonReference::fromSource('gh:o/a')->addonId());
    }
}
