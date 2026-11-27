<?php
namespace Tests\Source;

use OSC\Addon\GitRef;
use OSC\Source\Document;
use OSC\Source\DocumentSourceResolver;
use OSC\Source\SourceException;
use OSC\Source\SourceForm;
use OSC\Source\GitHost\GitHubHost;
use OSC\Source\GitHost\GitLabHost;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Helper\Path;
use OSC\Helper\UrlCredentials;
use OSC\Source\GitFile;
use OSC\Source\GitRepository;
use OSC\Source\HttpUrl;
use OSC\Source\LocalPath;
use OSC\Source\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentSourceResolver::class)]
#[CoversClass(Document::class)]
#[CoversClass(SourceException::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(GitHubHost::class)]
#[UsesClass(GitLabHost::class)]
#[UsesClass(GitPathSegments::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(Path::class)]
#[UsesClass(UrlCredentials::class)]
#[UsesClass(GitFile::class)]
#[UsesClass(GitRepository::class)]
#[UsesClass(HttpUrl::class)]
#[UsesClass(LocalPath::class)]
#[UsesClass(Location::class)]
class DocumentSourceResolverTest extends TestCase
{
    private const ROOT = '/srv/bp';
    private const URL_ROOT = 'https://example.org/bp/';

    private function local(string $file = 'blueprint.json'): Document
    {
        return new Document(LocalPath::fromAbsolute(self::ROOT . '/' . $file), self::ROOT);
    }

    private function remote(string $file = 'blueprint.json'): Document
    {
        return new Document(HttpUrl::fromUrl(self::URL_ROOT . $file), self::URL_ROOT);
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
        $this->assertSame($expected, DocumentSourceResolver::file($raw, $this->local())->value());
    }

    public function testResolvesAgainstTheFileThatDeclaresIt(): void
    {
        $list = $this->local('lists/more/modules.json');
        $this->assertSame('/srv/bp/lists/zips/N.zip', DocumentSourceResolver::file('../zips/N.zip', $list)->value());
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
            DocumentSourceResolver::file($raw, $this->local($declaredIn))->value();
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
            'nul byte'          => ['./a%00b.zip', '%00'],
        ];
    }

    /** Only the written path is decoded: a parent folder whose name contains `%` keeps it. */
    public function testTheParentFolderIsNotDecoded(): void
    {
        $parent = new Document(LocalPath::fromAbsolute('/nonexistent/My%20Docs/blueprint.json'), '/nonexistent/My%20Docs');
        $this->assertSame('/nonexistent/My%20Docs/a b.zip', DocumentSourceResolver::file('./a%20b.zip', $parent)->value());
    }

    #[DataProvider('badLocalPaths')]
    public function testRejectsALocalPathThatCannotNameAFile(string $raw, string $hint): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage($hint);
        DocumentSourceResolver::file($raw, $this->local())->value();
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
            DocumentSourceResolver::file($raw, $this->local())->value();
            $this->fail("'{$raw}' should be rejected");
        } catch (SourceException $e) {
            $this->assertSame(SourceException::INVALID, $e->reason);
        }
    }

    public function testKeepsAUrl(): void
    {
        $this->assertSame('https://example.org/a.zip', DocumentSourceResolver::file('https://example.org/a.zip', $this->local())->value());
        $this->assertSame('https://example.org/a.zip', DocumentSourceResolver::file('HTTPS://example.org/a.zip', $this->local())->value());
    }

    public function testTurnsARepositoryFileIntoARawUrl(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/o/r/v1/dist/M.zip',
            DocumentSourceResolver::file('gh:o/r@v1:dist/M.zip', $this->local())->value()
        );
    }

    public function testARepositoryThatEscapesIsInvalid(): void
    {
        $this->expectException(SourceException::class);
        DocumentSourceResolver::file('gh:trusted/..@attacker:evil.php', $this->local())->value();
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
        DocumentSourceResolver::file($raw, $this->local())->value();
    }

    #[DataProvider('repositories')]
    public function testARepositoryIsKeptForAnAddon(string $raw): void
    {
        $repository = DocumentSourceResolver::addon($raw, $this->local());
        $this->assertInstanceOf(GitRepository::class, $repository);
        $this->assertSame($raw, $repository->original());
    }

    public function testResolvesAgainstARemoteDocument(): void
    {
        $this->assertSame('https://example.org/bp/zips/M.zip', DocumentSourceResolver::file('./zips/M.zip', $this->remote())->value());
        $this->assertSame('https://example.org/bp/b.zip', DocumentSourceResolver::file('../b.zip', $this->remote('lists/x.json'))->value());
        // a URL is fetched as it is: the query stays
        $this->assertSame('https://example.org/bp/a.json?x=1', DocumentSourceResolver::file('./a.json?x=1', $this->remote())->value());
    }

    public function testEncodesCharactersAUrlCannotCarryAgainstARemoteDocument(): void
    {
        // written as is or encoded, both name the same file
        $this->assertSame('https://example.org/bp/My%20File.pdf', DocumentSourceResolver::file('My File.pdf', $this->remote())->value());
        $this->assertSame('https://example.org/bp/My%20File.pdf', DocumentSourceResolver::file('./My%20File.pdf', $this->remote())->value());
    }

    /** @return array<string,array{0:string}> spec fixtures/resolution/encoded-paths, from a URL */
    public static function encodedSeparators(): array
    {
        return array_map(fn($v) => [$v], ['slash' => './a%2Fb.pdf', 'backslash' => './a%5Cb.pdf', 'lowercase' => './c%2fd.pdf']);
    }

    /** The same result as from disk (the spec: "from disk and from a URL"). */
    #[DataProvider('encodedSeparators')]
    public function testRejectsAnEncodedSeparatorAgainstARemoteDocument(string $raw): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage("decodes to '/' or '\\'");
        DocumentSourceResolver::file($raw, $this->remote());
    }

    public function testAnEncodedSlashInARemoteQueryIsKept(): void
    {
        $this->assertSame('https://example.org/bp/a.json?x=%2F', DocumentSourceResolver::file('./a.json?x=%2F', $this->remote())->value());
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
            DocumentSourceResolver::file($raw, $this->remote())->value();
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
        $blueprint = HttpUrl::fromUrl($url);
        $document = new Document($blueprint, $blueprint->folder());
        $this->assertSame('https://example.org/bp/lists/a.json', DocumentSourceResolver::file('./lists/a.json', $document)->value());
    }

    public function testARemoteRootMessageDoesNotSuggestRoot(): void
    {
        try {
            DocumentSourceResolver::file('../x.zip', $this->remote())->value();
            $this->fail('should leave the root');
        } catch (SourceException $e) {
            $this->assertStringNotContainsString('--root', $e->getMessage());
        }
    }

    public function testMessagesHideCredentials(): void
    {
        $base = new Document(HttpUrl::fromUrl('https://u:secret@example.org/bp/blueprint.json'), 'https://u:secret@example.org/bp/');
        try {
            DocumentSourceResolver::file('../x.zip', $base);
            $this->fail('should leave the root');
        } catch (SourceException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testTheRootOfAnImport(): void
    {
        $local = $this->local();
        // by relative path: the importer's root
        $this->assertSame(self::ROOT, DocumentSourceResolver::importRoot('./lists/a.json', LocalPath::fromAbsolute('/srv/bp/lists/a.json'), $local));
        // by absolute URL or gh:/gl: the folder of the file
        $this->assertSame('https://example.org/lists/', DocumentSourceResolver::importRoot('https://example.org/lists/a.json', HttpUrl::fromUrl('https://example.org/lists/a.json'), $local));
        $raw = 'https://raw.githubusercontent.com/o/r/main/lists/a.json';
        $this->assertSame('https://raw.githubusercontent.com/o/r/main/lists/', DocumentSourceResolver::importRoot('gh:o/r@main:lists/a.json', HttpUrl::fromUrl($raw), $local));
    }

    public function testResultsAreTypedAndKeepTheOriginal(): void
    {
        $local = DocumentSourceResolver::file('./My%20File.pdf', $this->local());
        $this->assertInstanceOf(LocalPath::class, $local);
        $this->assertSame('./My%20File.pdf', $local->original());

        $gitFile = DocumentSourceResolver::file('gh:o/r@v1:a.json', $this->local());
        $this->assertInstanceOf(HttpUrl::class, $gitFile);
        $this->assertSame('gh:o/r@v1:a.json', $gitFile->original());

        $remote = DocumentSourceResolver::file('a b.json', $this->remote());
        $this->assertInstanceOf(HttpUrl::class, $remote);
        $this->assertSame('a b.json', $remote->original());

        $this->assertInstanceOf(HttpUrl::class, DocumentSourceResolver::addon('https://example.org/M.zip', $this->local()));
    }

    /**
     * The relative path itself is Path::relative()'s (see PathTest); here, that each segment is
     * written as in a URL.
     *
     * @return array<string,array{0:string,1:string,2:string}> target, folder → reference
     */
    public static function references(): array
    {
        return [
            'same folder'    => ['/w/cfg/x.ttl', '/w/cfg', 'x.ttl'],
            'up and a space' => ['/w/rdf/a b.ttl', '/w/cfg', '../rdf/a%20b.ttl'],
            'a hash'         => ['/w/cfg/n#1.ttl', '/w/cfg', 'n%231.ttl'],
            'a question mark and a percent' => ['/w/cfg/a?b%c.ttl', '/w/cfg', 'a%3Fb%25c.ttl'],
        ];
    }

    #[DataProvider('references')]
    public function testReference(string $target, string $folder, string $reference): void
    {
        $this->assertSame($reference, DocumentSourceResolver::reference(LocalPath::fromAbsolute($target), $folder));
    }

    public function testReferenceRoundTrips(): void
    {
        $dir = realpath(sys_get_temp_dir()) . '/osc-rel-' . bin2hex(random_bytes(4));
        mkdir("{$dir}/cfg", 0777, true);
        mkdir("{$dir}/rdf", 0777, true);
        file_put_contents("{$dir}/cfg/c.json", '{}');
        try {
            $reference = DocumentSourceResolver::reference(LocalPath::fromAbsolute("{$dir}/rdf/a b#1.ttl"), "{$dir}/cfg");
            $document = Document::open(LocalPath::fromAbsolute("{$dir}/cfg/c.json"), $dir);
            $this->assertSame("{$dir}/rdf/a b#1.ttl", $document->resolve($reference)->value());
        } finally {
            Path::removeFolder($dir);
        }
    }
}
