<?php
namespace Tests\Blueprint;

use OSC\Addon\GitRef;
use OSC\Blueprint\Document;
use OSC\Blueprint\SourceException;
use OSC\Blueprint\SourceForm;
use OSC\Blueprint\SourceResolver;
use OSC\Helper\Reference\GitHubProvider;
use OSC\Helper\Reference\GitLabProvider;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\Reference\RepoSegments;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceResolver::class)]
#[CoversClass(Document::class)]
#[CoversClass(SourceException::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(ReferenceResolver::class)]
#[UsesClass(GitHubProvider::class)]
#[UsesClass(GitLabProvider::class)]
#[UsesClass(RepoSegments::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(ResourceFetcher::class)]
class SourceResolverTest extends TestCase
{
    private const ROOT = '/srv/bp';
    private const URL_ROOT = 'https://example.org/bp/';

    private SourceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SourceResolver(ReferenceResolver::withDefaults());
    }

    private function local(string $file = 'blueprint.json'): Document
    {
        return new Document(self::ROOT . '/' . $file, self::ROOT);
    }

    private function remote(string $file = 'blueprint.json'): Document
    {
        return new Document(self::URL_ROOT . $file, self::URL_ROOT);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function localPaths(): array
    {
        return [
            'dot slash'            => ['./config/a.php', '/srv/bp/config/a.php'],
            'plain'                => ['config/b.php', '/srv/bp/config/b.php'],
            'encoded space'        => ['./My%20File.pdf', '/srv/bp/My File.pdf'],
            'raw space'            => ['My File.pdf', '/srv/bp/My File.pdf'],
            'encoded hash'         => ['./notes%231.pdf', '/srv/bp/notes#1.pdf'],
            'backslash separators' => ['.\\zips\\M.zip', '/srv/bp/zips/M.zip'],
            'up inside the root'   => ['a/../b.php', '/srv/bp/b.php'],
            'colon after ./'       => ['./10:00.json', '/srv/bp/10:00.json'],
            'trailing space kept'  => ['./a.zip ', '/srv/bp/a.zip '],
            'unicode'              => ["caf\u{00e9}.jpg", "/srv/bp/caf\u{00e9}.jpg"],
        ];
    }

    #[DataProvider('localPaths')]
    public function testResolvesARelativePathAgainstALocalDocument(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolve($raw, $this->local()));
    }

    public function testResolvesAgainstTheFileThatDeclaresIt(): void
    {
        $list = $this->local('lists/more/modules.json');
        $this->assertSame('/srv/bp/lists/zips/N.zip', $this->resolver->resolve('../zips/N.zip', $list));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function escapes(): array
    {
        return [
            'parent'                 => ['../outside.php', 'blueprint.json'],
            'encoded parent'         => ['%2e%2e/%2E%2E/outside.php', 'blueprint.json'],
            'mixed encoded parent'   => ['.%2E/outside.php', 'blueprint.json'],
            'backslash parent'       => ['..\\outside.php', 'blueprint.json'],
            'from an imported list'  => ['../../M.zip', 'lists/modules.json'],
        ];
    }

    #[DataProvider('escapes')]
    public function testRejectsAPathThatLeavesTheRoot(string $raw, string $declaredIn): void
    {
        try {
            $this->resolver->resolve($raw, $this->local($declaredIn));
            $this->fail("'{$raw}' should leave the root");
        } catch (SourceException $e) {
            $this->assertSame(SourceException::OUTSIDE_ROOT, $e->reason);
            $this->assertStringContainsString('--root', $e->getMessage());
        }
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function badLocalPaths(): array
    {
        return [
            'encoded slash'     => ['./a%2Fb.php', '%2F'],
            'encoded backslash' => ['./a%5cb.php', '%5c'],
            'unencoded hash'    => ['./notes#1.pdf', '%23'],
            'unencoded query'   => ['./a.json?x=1', '%3F'],
        ];
    }

    #[DataProvider('badLocalPaths')]
    public function testRejectsALocalPathThatCannotNameAFile(string $raw, string $hint): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage($hint);
        $this->resolver->resolve($raw, $this->local());
    }

    /** @return array<string,array{0:string}> */
    public static function notSources(): array
    {
        return array_map(fn($v) => [$v], [
            'absolute' => '/etc/a.php', 'drive' => 'C:/a.php', 'unc' => '\\\\server\\a.php', 'leading space' => ' ./a.zip',
            'empty' => '', 'id:version' => 'CleanUrl:3.4', 'one-letter scheme' => 'a:b.json', 'file url' => 'file:///etc/passwd',
            'php' => 'php://input',
        ]);
    }

    #[DataProvider('notSources')]
    public function testRejectsAValueThatIsNotASource(string $raw): void
    {
        try {
            $this->resolver->resolve($raw, $this->local());
            $this->fail("'{$raw}' should be rejected");
        } catch (SourceException $e) {
            $this->assertSame(SourceException::INVALID, $e->reason);
        }
    }

    public function testKeepsAUrl(): void
    {
        $this->assertSame('https://example.org/a.zip', $this->resolver->resolve('https://example.org/a.zip', $this->local()));
        $this->assertSame('HTTPS://example.org/a.zip', $this->resolver->resolve('HTTPS://example.org/a.zip', $this->local()));
    }

    public function testTurnsARepositoryFileIntoARawUrl(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/o/r/v1/dist/M.zip',
            $this->resolver->resolve('gh:o/r@v1:dist/M.zip', $this->local())
        );
    }

    public function testARepositoryThatEscapesIsInvalid(): void
    {
        $this->expectException(SourceException::class);
        $this->resolver->resolve('gh:trusted/..@attacker:evil.php', $this->local());
    }

    /** @return array<string,array{0:string}> */
    public static function repositories(): array
    {
        return array_map(fn($v) => [$v], [
            'gh' => 'gh:o/r', 'gh ref' => 'gh:o/r#v1', 'github url' => 'https://github.com/o/r', 'github slash' => 'https://github.com/o/r/',
            'dot git' => 'https://gitlab.com/g/r.git#v2', 'ssh' => 'git@gitlab.com:g/h.git',
        ]);
    }

    #[DataProvider('repositories')]
    public function testARepositoryIsNotAFile(string $raw): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('repository');
        $this->resolver->resolve($raw, $this->local());
    }

    #[DataProvider('repositories')]
    public function testARepositoryIsKeptForAnAddon(string $raw): void
    {
        $this->assertSame($raw, $this->resolver->resolve($raw, $this->local(), true));
    }

    public function testResolvesAgainstARemoteDocument(): void
    {
        $this->assertSame('https://example.org/bp/zips/M.zip', $this->resolver->resolve('./zips/M.zip', $this->remote()));
        $this->assertSame('https://example.org/bp/b.zip', $this->resolver->resolve('../b.zip', $this->remote('lists/x.json')));
        // a URL is fetched as it is: the query stays
        $this->assertSame('https://example.org/bp/a.json?x=1', $this->resolver->resolve('./a.json?x=1', $this->remote()));
    }

    public function testEncodesCharactersAUrlCannotCarryAgainstARemoteDocument(): void
    {
        // written as is or encoded, both name the same file
        $this->assertSame('https://example.org/bp/My%20File.pdf', $this->resolver->resolve('My File.pdf', $this->remote()));
        $this->assertSame('https://example.org/bp/My%20File.pdf', $this->resolver->resolve('./My%20File.pdf', $this->remote()));
    }

    /** @return array<string,array{0:string}> */
    public static function remoteEscapes(): array
    {
        return array_map(fn($v) => [$v], ['parent' => '../x.zip', 'encoded' => '%2e%2e/x.zip', 'backslash' => '..\\x.zip']);
    }

    #[DataProvider('remoteEscapes')]
    public function testRejectsAPathThatLeavesARemoteRoot(string $raw): void
    {
        try {
            $this->resolver->resolve($raw, $this->remote());
            $this->fail("'{$raw}' should leave the root");
        } catch (SourceException $e) {
            $this->assertSame(SourceException::OUTSIDE_ROOT, $e->reason);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function unnormalizedBlueprintUrls(): array
    {
        return array_map(fn($v) => [$v], [
            'uppercase scheme' => 'HTTPS://example.org/bp/blueprint.json',
            'uppercase host'   => 'https://Example.ORG/bp/blueprint.json',
            'default port'     => 'https://example.org:443/bp/blueprint.json',
            'dot segment'      => 'https://example.org/bp/./blueprint.json',
        ]);
    }

    #[DataProvider('unnormalizedBlueprintUrls')]
    public function testTheRootOfAnUnnormalizedUrlIsNormalizedLikeTheResolvedSource(string $url): void
    {
        $document = new Document($url, SourceResolver::folderUrl($url));
        $this->assertSame('https://example.org/bp/lists/a.json', $this->resolver->resolve('./lists/a.json', $document));
        $this->assertSame('https://example.org/bp/', SourceResolver::folderUrl($url));
    }

    public function testARemoteRootMessageDoesNotSuggestRoot(): void
    {
        try {
            $this->resolver->resolve('../x.zip', $this->remote());
            $this->fail('should leave the root');
        } catch (SourceException $e) {
            $this->assertStringNotContainsString('--root', $e->getMessage());
        }
    }

    public function testMessagesHideCredentials(): void
    {
        $base = new Document('https://u:secret@example.org/bp/blueprint.json', 'https://u:secret@example.org/bp/');
        try {
            $this->resolver->resolve('../x.zip', $base);
            $this->fail('should leave the root');
        } catch (SourceException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testTheRootOfAnImport(): void
    {
        $local = $this->local();
        // by relative path: the importer's root
        $this->assertSame(self::ROOT, $this->resolver->importRoot('./lists/a.json', '/srv/bp/lists/a.json', $local));
        // by absolute URL or gh:/gl: the folder of the file
        $this->assertSame('https://example.org/lists/', $this->resolver->importRoot('https://example.org/lists/a.json', 'https://example.org/lists/a.json', $local));
        $raw = 'https://raw.githubusercontent.com/o/r/main/lists/a.json';
        $this->assertSame('https://raw.githubusercontent.com/o/r/main/lists/', $this->resolver->importRoot('gh:o/r@main:lists/a.json', $raw, $local));
    }
}
