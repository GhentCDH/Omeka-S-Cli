<?php
namespace Tests\Blueprint;

use Exception;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\DocumentValidator;
use OSC\Source\SourceException;
use OSC\Blueprint\SourceFields;
use OSC\Helper\ResourceFetcher;
use OSC\Source\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintLoader::class)]
#[UsesClass(Blueprint::class)]
#[UsesClass(SourceFields::class)]
class BlueprintLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp_loader_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTree($this->dir);
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /** Write a fixture at a (possibly nested) relative path, creating parent directories. */
    private function writeIn(string $relPath, string $content): string
    {
        $path = $this->dir . '/' . $relPath;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
        return $path;
    }

    public function testResolvesImportUnderTheKeyAndMergesDuplicatesIntoTheFirst(): void
    {
        $this->write('more.jsonc', '["B", { "name": "C" }]');
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "modules": [
                "A",
                { "\$import": "./more.jsonc" },
                { "name": "A", "state": "install" }
            ]
        }
        JSONC);

        $blueprint = (new BlueprintLoader())->load($base);

        // A keeps its first position; the later object form is merged into the bare name
        $this->assertSame(
            [['name' => 'A', 'state' => 'install'], 'B', ['name' => 'C']],
            $blueprint->modules()
        );
    }

    public function testSettingsListMergesMapsAndImportsInOrder(): void
    {
        $this->write('s.jsonc', '{ "b": 3, "c": 4 }');
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "settings": [
                { "a": 1 },
                { "b": 2 },
                { "\$import": "./s.jsonc" }
            ]
        }
        JSONC);

        $blueprint = (new BlueprintLoader())->load($base);
        $this->assertSame(['a' => 1, 'b' => 3, 'c' => 4], $blueprint->settings());
    }

    public function testInlineSettingsMapIsReturnedAsIs(): void
    {
        $base = $this->write('base.jsonc', '{ "settings": { "installation_title": "x" } }');
        $blueprint = (new BlueprintLoader())->load($base);
        $this->assertSame(['installation_title' => 'x'], $blueprint->settings());
    }

    public function testCircularImportIsRejected(): void
    {
        $this->write('x.jsonc', '[ { "$import": "./y.jsonc" } ]');
        $this->write('y.jsonc', '[ { "$import": "./x.jsonc" } ]');
        $base = $this->write('base.jsonc', '{ "modules": [ { "$import": "./x.jsonc" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');
        (new BlueprintLoader())->load($base);
    }

    public function testLoadPartialReturnsAResolvedList(): void
    {
        $file = $this->write('modules.jsonc', '[ "A", { "name": "B", "state": "install" } ]');
        $modules = (new BlueprintLoader())->loadPartial($file, 'modules');
        $this->assertSame(['A', ['name' => 'B', 'state' => 'install']], $modules);
    }

    public function testRejectsNonObjectBlueprint(): void
    {
        $file = $this->write('bad.jsonc', '[ "A" ]');
        $this->expectException(Exception::class);
        (new BlueprintLoader())->load($file);
    }

    public function testRoutesSourceAndImportsThroughTheInjectedFetch(): void
    {
        // gh:fake/repo:<name> is fetched from its raw URL, which the fake fetch maps to a file in the temp
        // dir: both the top-level source and a nested $import go through GitFile and the injected fetch
        $this->write('more.jsonc', '["B"]');
        $this->write('base.jsonc', '{ "modules": ["A", { "$import": "gh:fake/repo:more.jsonc" }] }');

        $blueprint = $this->loaderWithFakeRepository()->load('gh:fake/repo:base.jsonc');

        $names = array_map(fn($m) => is_string($m) ? $m : $m['name'], $blueprint->modules());
        sort($names);
        $this->assertSame(['A', 'B'], $names);
    }

    public function testOverridingDuplicateRecordsAWarning(): void
    {
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "modules": [
                { "name": "Log", "state": "download" },
                { "name": "Log", "state": "activate" }
            ]
        }
        JSONC);

        $loader = new BlueprintLoader();
        $loader->load($base);
        $warnings = $loader->takeWarnings();

        $this->assertCount(1, $warnings);
        // the identity label keeps its original casing, and the message names the list key
        $this->assertStringContainsString("modules: 'Log'", $warnings[0]);
        // draining clears the buffer
        $this->assertSame([], $loader->takeWarnings());
    }

    public function testIdenticalDuplicateDoesNotWarn(): void
    {
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "modules": [
                { "name": "Log", "state": "download" },
                { "name": "Log", "state": "download" }
            ]
        }
        JSONC);

        $loader = new BlueprintLoader();
        $loader->load($base);

        // a duplicate that re-declares an identical value is a harmless no-op, not a warning
        $this->assertSame([], $loader->takeWarnings());
    }

    // ── vocabulary `source` resolution ───────────────────────────────────────────────────────────

    /** @return array<string,string> A vocabulary entry with the given source field(s) merged in */
    private function vocab(array $extra): array
    {
        return ['namespaceUri' => 'http://example.org/ns#', 'prefix' => 'ex', 'label' => 'Ex'] + $extra;
    }

    public function testInlineVocabularyRelativeSourceResolvesAgainstBlueprint(): void
    {
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [$this->vocab(['source' => 'schema.rdf'])],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();
        $this->assertSame($this->dir . '/schema.rdf', $vocabs[0]['source']);
    }

    public function testInlineVocabularyAbsolutePathSourceIsRejected(): void
    {
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [$this->vocab(['source' => '/abs/schema.rdf'])],
        ]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/absolute path/');
        (new BlueprintLoader())->load($base);
    }

    public function testInlineVocabularyUrlSourceUnchanged(): void
    {
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [$this->vocab(['source' => 'https://example.org/schema.rdf'])],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();
        $this->assertSame('https://example.org/schema.rdf', $vocabs[0]['source']);
    }

    public function testImportedVocabularyConfigRelativeSourceResolvesAgainstTheConfig(): void
    {
        // the config lives in a subdirectory; its relative source must resolve against THAT directory,
        // not the top-level blueprint's directory
        $this->writeIn('vocab/schema.jsonc', json_encode($this->vocab(['source' => 'schema.rdf'])));
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [['$import' => './vocab/schema.jsonc']],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();
        $this->assertCount(1, $vocabs);
        $this->assertSame($this->dir . '/vocab/schema.rdf', $vocabs[0]['source']);
    }

    public function testImportedVocabularyConfigAbsoluteSourceUnchanged(): void
    {
        $this->writeIn('vocab/schema.jsonc', json_encode($this->vocab(['source' => 'https://example.org/schema.rdf'])));
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [['$import' => './vocab/schema.jsonc']],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();
        $this->assertSame('https://example.org/schema.rdf', $vocabs[0]['source']);
    }

    public function testImportedVocabularyConfigRelativeParentSourceResolvesAgainstTheConfig(): void
    {
        // config in a subdir pointing one level up (../rdf/x.rdf) resolves relative to the config
        $this->writeIn('vocab/schema.jsonc', json_encode($this->vocab(['source' => '../rdf/schema.rdf'])));
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [['$import' => './vocab/schema.jsonc']],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();
        $this->assertSame($this->dir . '/rdf/schema.rdf', $vocabs[0]['source']);
    }

    public function testNestedImportResolvesSourceAgainstInnermostConfig(): void
    {
        // site -> vocab/list.jsonc -> deep/schema.jsonc; the source resolves against deep/
        $this->writeIn('vocab/deep/schema.jsonc', json_encode($this->vocab(['source' => 'schema.rdf'])));
        $this->writeIn('vocab/list.jsonc', json_encode([['$import' => './deep/schema.jsonc']]));
        $base = $this->writeIn('site.jsonc', json_encode([
            'vocabularies' => [['$import' => './vocab/list.jsonc']],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();
        $this->assertSame($this->dir . '/vocab/deep/schema.rdf', $vocabs[0]['source']);
    }

    // ── resource-template `source` resolution (same two-level rule) ───────────────────────────────

    public function testInlineResourceTemplateRelativeSourceResolvesAgainstBlueprint(): void
    {
        $base = $this->writeIn('site.jsonc', json_encode([
            'resourceTemplates' => [['label' => 'T', 'source' => 'tpl.json']],
        ]));

        $templates = (new BlueprintLoader())->load($base)->resourceTemplates();
        $this->assertSame($this->dir . '/tpl.json', $templates[0]['source']);
    }

    public function testImportedResourceTemplateConfigRelativeSourceResolvesAgainstTheConfig(): void
    {
        $this->writeIn('rt/base.jsonc', json_encode(['label' => 'T', 'source' => 'tpl.json']));
        $base = $this->writeIn('site.jsonc', json_encode([
            'resourceTemplates' => [['$import' => './rt/base.jsonc']],
        ]));

        $templates = (new BlueprintLoader())->load($base)->resourceTemplates();
        $this->assertCount(1, $templates);
        $this->assertSame($this->dir . '/rt/tpl.json', $templates[0]['source']);
    }

    // ── file `source` resolution and de-duplication ──────────────────────────────────────────────

    public function testInlineFileRelativeSourceResolvesAgainstBlueprint(): void
    {
        $base = $this->writeIn('site.jsonc', json_encode([
            'files' => [['source' => './config/a.php', 'destination' => 'config/a.php']],
        ]));

        $files = (new BlueprintLoader())->load($base)->files();
        $this->assertSame($this->dir . '/config/a.php', $files[0]['source']);
    }

    public function testImportedFileListRelativeSourceResolvesAgainstTheList(): void
    {
        $this->writeIn('files/list.jsonc', json_encode([['source' => 'a.php', 'destination' => 'config/a.php']]));
        $base = $this->writeIn('site.jsonc', json_encode([
            'files' => [['$import' => './files/list.jsonc']],
        ]));

        $files = (new BlueprintLoader())->load($base)->files();
        $this->assertSame($this->dir . '/files/a.php', $files[0]['source']);
    }

    public function testFileUrlSourceUnchangedAndRepoReferenceBecomesRawUrl(): void
    {
        $base = $this->writeIn('site.jsonc', json_encode([
            'files' => [
                ['source' => 'https://example.org/b.zip', 'destination' => 'files/b', 'extract' => true],
                ['source' => 'gh:owner/repo@v1:config/c.php', 'destination' => 'config/c.php'],
            ],
        ]));

        $sources = array_column((new BlueprintLoader())->load($base)->files(), 'source');
        $this->assertSame([
            'https://example.org/b.zip',
            'https://raw.githubusercontent.com/owner/repo/v1/config/c.php',
        ], $sources);
    }

    public function testFilesDeduplicateByDestination(): void
    {
        $this->write('files.jsonc', '[{ "source": "a.php", "destination": "config/a.php" }]');
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "files": [
                { "\$import": "./files.jsonc" },
                { "source": "b.php", "destination": "config/a.php" }
            ]
        }
        JSONC);

        $loader = new BlueprintLoader();
        $files = $loader->load($base)->files();

        $this->assertSame([['source' => $this->dir . '/b.php', 'destination' => 'config/a.php']], $files);
        $this->assertNotEmpty($loader->takeWarnings());
    }

    public function testSitesResolveImportsAndDeduplicateBySlugOrTitle(): void
    {
        $this->write('sites.jsonc', '[{ "title": "Site B", "slug": "site-b" }]');
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "sites": [
                { "title": "Site A" },
                { "\$import": "./sites.jsonc" },
                { "title": "Site B, renamed", "slug": "site-b" },
                { "title": "site a", "isPublic": false }
            ]
        }
        JSONC);

        $loader = new BlueprintLoader();
        $sites = $loader->load($base)->sites();

        // each site keeps its first position, with the later definition merged into it
        $this->assertSame(
            [['title' => 'site a', 'isPublic' => false], ['title' => 'Site B, renamed', 'slug' => 'site-b']],
            $sites
        );
        $warnings = $loader->takeWarnings();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString("sites: 'site-b'", $warnings[0]);
        $this->assertStringContainsString("sites: 'site a'", $warnings[1]);
    }

    // ── de-duplication: first position, shallow merge ───────────────────────────────────────────

    public function testDuplicateModuleKeepsItsPositionAndMergesFields(): void
    {
        $this->write('override.jsonc', '[{ "name": "Common", "version": "3.4.72" }]');
        $base = $this->write('base.jsonc', <<<JSONC
        {
            "modules": [
                { "name": "Common", "state": "install", "version": "3.4.71" },
                "AdvancedSearch",
                { "\$import": "./override.jsonc" }
            ]
        }
        JSONC);

        $loader = new BlueprintLoader();
        $modules = $loader->load($base)->modules();

        // Common stays before the module that depends on it; version updated, state kept
        $this->assertSame(
            [['name' => 'Common', 'state' => 'install', 'version' => '3.4.72'], 'AdvancedSearch'],
            $modules
        );
        $this->assertCount(1, $loader->takeWarnings());
    }

    public function testBareNameAfterObjectDoesNotChangeOrWarn(): void
    {
        $base = $this->write('base.jsonc', '{ "modules": [ { "name": "Log", "state": "download" }, "Log" ] }');

        $loader = new BlueprintLoader();
        $this->assertSame([['name' => 'Log', 'state' => 'download']], $loader->load($base)->modules());
        $this->assertSame([], $loader->takeWarnings());
    }

    public function testEntriesWithoutIdentityKeepTheirPosition(): void
    {
        $base = $this->write('base.jsonc', '{ "users": [ { "role": "editor" }, { "email": "a@x.org" }, { "role": "author" } ] }');

        $users = (new BlueprintLoader())->load($base)->users();
        $this->assertSame([['role' => 'editor'], ['email' => 'a@x.org'], ['role' => 'author']], $users);
    }

    public function testVocabulariesDeduplicateByNamespaceUri(): void
    {
        $base = $this->write('base.jsonc', json_encode([
            'vocabularies' => [
                ['namespaceUri' => 'http://a.org/#', 'prefix' => 'ex', 'label' => 'A', 'source' => 'https://a.org/a.rdf'],
                ['namespaceUri' => 'http://b.org/#', 'prefix' => 'ex', 'label' => 'B', 'source' => 'https://b.org/b.rdf'],
                ['namespaceUri' => 'http://a.org/#', 'prefix' => 'a', 'label' => 'A2', 'source' => 'https://a.org/a.rdf'],
            ],
        ]));

        $vocabs = (new BlueprintLoader())->load($base)->vocabularies();

        // the same prefix with different namespaces stays two vocabularies; the same namespace merges
        $this->assertSame(['A2', 'B'], array_column($vocabs, 'label'));
        $this->assertSame(['a', 'ex'], array_column($vocabs, 'prefix'));
    }

    // ── references: absolute paths, file: URLs and the blueprint root ───────────────────────────

    public function testAbsoluteImportIsRejected(): void
    {
        $this->write('more.jsonc', '["B"]');
        $base = $this->write('base.jsonc', json_encode(['modules' => [['$import' => $this->dir . '/more.jsonc']]]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/absolute path/');
        (new BlueprintLoader())->load($base);
    }

    public function testFileUrlImportIsRejected(): void
    {
        $base = $this->write('base.jsonc', json_encode(['modules' => [['$import' => 'file:///etc/passwd']]]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/URI scheme/');
        (new BlueprintLoader())->load($base);
    }

    public function testAPhpFilterSourceIsRejected(): void
    {
        $base = $this->write('base.jsonc', json_encode([
            'files' => [['source' => 'php://filter/resource=/etc/hostname', 'destination' => 'files/x.txt']],
        ]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            "files 'files/x.txt' source: Source 'php://filter/resource=/etc/hostname' in '{$base}' starts with a URI scheme"
        );
        (new BlueprintLoader())->load($base);
    }

    public function testOtherSchemesAreRejectedEverywhere(): void
    {
        $cases = [
            ['modules' => [['$import' => 'ftp://example.org/modules.jsonc']]],
            ['vocabularies' => [$this->vocab(['source' => 'gopher://127.0.0.1:6379/_x'])]],
            ['resourceTemplates' => [['source' => 'data://text/plain;base64,e30=']]],
        ];
        foreach ($cases as $i => $blueprint) {
            $base = $this->write("base{$i}.jsonc", json_encode($blueprint));
            try {
                (new BlueprintLoader())->load($base);
                $this->fail('case ' . $i . ' must be rejected');
            } catch (Exception $e) {
                $this->assertStringContainsString('starts with a URI scheme', $e->getMessage(), "case {$i}");
            }
        }
    }

    public function testAnUnsupportedSchemeIsRejectedBeforeTheSelfContainedCheck(): void
    {
        $base = $this->write('base.jsonc', json_encode([
            'files' => [['source' => 'phar:///tmp/x.phar/y', 'destination' => 'files/x.txt']],
        ]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('starts with a URI scheme');
        (new BlueprintLoader(null, null, true))->load($base);
    }

    public function testAbsoluteSettingsImportIsRejected(): void
    {
        $this->write('s.jsonc', '{ "a": 1 }');
        $base = $this->write('base.jsonc', json_encode(['settings' => [['$import' => $this->dir . '/s.jsonc']]]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/absolute path/');
        (new BlueprintLoader())->load($base);
    }

    public function testRelativeImportEscapingTheRootIsRejected(): void
    {
        $this->writeIn('shared/modules.jsonc', '["B"]');
        $base = $this->writeIn('site/base.jsonc', '{ "modules": [ { "$import": "../shared/modules.jsonc" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/outside the root/');
        (new BlueprintLoader())->load($base);
    }

    public function testWiderRootAllowsAnEscapingImport(): void
    {
        $this->writeIn('shared/modules.jsonc', '["B"]');
        $base = $this->writeIn('site/base.jsonc', '{ "modules": [ "A", { "$import": "../shared/modules.jsonc" } ] }');

        $modules = (new BlueprintLoader(null, $this->dir))->load($base)->modules();
        $this->assertSame(['A', 'B'], $modules);
    }

    public function testUpwardImportInsideTheRootIsAllowed(): void
    {
        // vocab/deep/x.jsonc -> ../shared.jsonc stays inside the blueprint's directory
        $this->writeIn('vocab/shared.jsonc', '["B"]');
        $this->writeIn('vocab/deep/list.jsonc', '[ { "$import": "../shared.jsonc" } ]');
        $base = $this->writeIn('base.jsonc', '{ "modules": [ { "$import": "./vocab/deep/list.jsonc" } ] }');

        $this->assertSame(['B'], (new BlueprintLoader())->load($base)->modules());
    }

    public function testSourceEscapingTheRootIsRejected(): void
    {
        $base = $this->writeIn('site/base.jsonc', json_encode([
            'files' => [['source' => '../../../../../../etc/passwd', 'destination' => 'files/x']],
        ]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/outside the root/');
        (new BlueprintLoader())->load($base);
    }

    public function testMissingRootIsRejected(): void
    {
        $base = $this->write('base.jsonc', '{ "modules": ["A"] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/not a directory/');
        (new BlueprintLoader(null, $this->dir . '/missing'))->load($base);
    }

    // ── import cycles and settings imports ──────────────────────────────────────────────────────

    public function testCircularSettingsImportIsRejected(): void
    {
        $this->write('a.jsonc', '[ { "$import": "./b.jsonc" } ]');
        $this->write('b.jsonc', '[ { "$import": "./a.jsonc" } ]');
        $base = $this->write('base.jsonc', '{ "settings": [ { "$import": "./a.jsonc" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');
        (new BlueprintLoader())->load($base);
    }

    public function testImportOfTheTopLevelBlueprintIsCircular(): void
    {
        $base = $this->write('base.jsonc', '{ "settings": [ { "$import": "./base.jsonc" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');
        (new BlueprintLoader())->load($base);
    }

    public function testNestedSettingsImportResolvesAgainstItsParent(): void
    {
        $this->writeIn('settings/deep/b.jsonc', '{ "b": 2 }');
        $this->writeIn('settings/a.jsonc', '[ { "a": 1 }, { "$import": "./deep/b.jsonc" } ]');
        $base = $this->writeIn('base.jsonc', '{ "settings": [ { "$import": "./settings/a.jsonc" } ] }');

        $this->assertSame(['a' => 1, 'b' => 2], (new BlueprintLoader())->load($base)->settings());
    }

    public function testDiamondImportIsNotCircular(): void
    {
        // the same file imported from two branches is not a cycle; its entries merge
        $this->write('common.jsonc', '["Common"]');
        $this->write('x.jsonc', '[ { "$import": "./common.jsonc" }, "X" ]');
        $this->write('y.jsonc', '[ { "$import": "./common.jsonc" }, "Y" ]');
        $base = $this->write('base.jsonc', '{ "modules": [ { "$import": "./x.jsonc" }, { "$import": "./y.jsonc" } ] }');

        $this->assertSame(['Common', 'X', 'Y'], (new BlueprintLoader())->load($base)->modules());
    }

    // ── add-on `source`: local zip releases ─────────────────────────────────────────────────────

    public function testModuleZipSourceResolvesAgainstTheDeclaringFile(): void
    {
        $this->writeIn('modules/list.jsonc', '[ { "name": "Common", "source": "./zips/Common-3.4.71.zip" } ]');
        $base = $this->writeIn('base.jsonc', json_encode([
            'modules' => [['$import' => './modules/list.jsonc']],
            'themes' => [['name' => 'freedom', 'source' => 'themes/freedom.zip']],
        ]));

        $blueprint = (new BlueprintLoader())->load($base);
        $this->assertSame($this->dir . '/modules/zips/Common-3.4.71.zip', $blueprint->modules()[0]['source']);
        $this->assertSame($this->dir . '/themes/freedom.zip', $blueprint->themes()[0]['source']);
    }

    public function testNonPathAddonSourcesAreKeptAsTheirCanonicalForm(): void
    {
        // a git repository becomes its clone URL (#ref kept); a URL is kept
        $sources = [
            'gh:Daniel-KM/Omeka-S-module-Common#3.4.71' => 'https://github.com/Daniel-KM/Omeka-S-module-Common.git#3.4.71',
            'https://github.com/Daniel-KM/Omeka-S-module-Log.git' => 'https://github.com/Daniel-KM/Omeka-S-module-Log.git',
            'https://github.com/Daniel-KM/Omeka-S-module-Log#3.4.30' => 'https://github.com/Daniel-KM/Omeka-S-module-Log.git#3.4.30',
            'git@github.com:Daniel-KM/Omeka-S-module-Log.git' => 'git@github.com:Daniel-KM/Omeka-S-module-Log.git',
            'https://example.org/AdvancedSearch-3.4.22.zip' => 'https://example.org/AdvancedSearch-3.4.22.zip',
        ];
        $written = array_keys($sources);
        $base = $this->write('base.jsonc', json_encode([
            'modules' => array_map(fn($s, $i) => ['name' => "M{$i}", 'source' => $s], $written, array_keys($written)),
        ]));

        $this->assertSame(array_values($sources), array_column((new BlueprintLoader())->load($base)->modules(), 'source'));
    }

    public function testAbsoluteAddonSourceIsRejected(): void
    {
        $base = $this->write('base.jsonc', '{ "modules": [ { "name": "Common", "source": "/tmp/Common.zip" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/absolute path/');
        (new BlueprintLoader())->load($base);
    }

    // ── x-omeka-s-cli.install.source: the Omeka S release ZIP ───────────────────────────────────

    /** The core source in the CLI's extension key (until the spec has install.source). */
    private function coreSource(string $source): string
    {
        return json_encode(['install' => ['title' => 'Demo'], 'x-omeka-s-cli' => ['install' => ['source' => $source]]]);
    }

    public function testInstallSourceResolvesAgainstTheBlueprint(): void
    {
        $base = $this->write('base.json', $this->coreSource('./core/omeka-s-4.1.1.zip'));

        $blueprint = (new BlueprintLoader())->load($base);

        $this->assertSame($this->dir . '/core/omeka-s-4.1.1.zip', $blueprint->installSource());
        $this->assertSame('Demo', $blueprint->install()['title']);
    }

    public function testInstallSourceUrlIsKept(): void
    {
        $url = 'https://github.com/omeka/omeka-s/releases/download/v4.1.1/omeka-s-4.1.1.zip';
        $base = $this->write('base.json', $this->coreSource($url));

        $this->assertSame($url, (new BlueprintLoader())->load($base)->installSource());
    }

    public function testInstallSourceMayNotBeAnAbsolutePath(): void
    {
        $base = $this->write('base.json', $this->coreSource('/tmp/omeka-s.zip'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('absolute path');
        (new BlueprintLoader())->load($base);
    }

    public function testWithoutInstallSourceThereIsNone(): void
    {
        $base = $this->write('base.json', '{ "install": { "title": "Demo" }, "x-omeka-s-cli": { "other": true } }');

        $this->assertNull((new BlueprintLoader())->load($base)->installSource());
    }

    // ── self-contained mode ─────────────────────────────────────────────────────────────────────

    public function testSelfContainedRecordsEveryNonRelativeSourceWithoutFetchingIt(): void
    {
        $base = $this->write('base.json', json_encode([
            'x-omeka-s-cli' => ['install' => ['source' => 'https://example.org/omeka-s.zip']],
            'modules' => [
                ['name' => 'Log', 'source' => 'gh:Daniel-KM/Omeka-S-module-Log'],
                ['name' => 'Local', 'source' => './modules/Local-1.0.0.zip'],
            ],
            'vocabularies' => [[
                'prefix' => 'ex', 'namespaceUri' => 'https://example.org/ns#', 'label' => 'Ex',
                'source' => 'https://example.org/ex.ttl',
            ]],
            // reported, never fetched: a unit test has no network, and .invalid never resolves
            'settings' => [['$import' => 'https://example.invalid/settings.json']],
        ]));

        $loader = new BlueprintLoader(null, null, true);
        $blueprint = $loader->load($base);
        $violations = implode("\n", $loader->takeViolations());

        $this->assertStringContainsString("x-omeka-s-cli.install source 'https://example.org/omeka-s.zip'", $violations);
        $this->assertStringContainsString("modules 'Log' source 'gh:Daniel-KM/Omeka-S-module-Log'", $violations);
        $this->assertStringContainsString("vocabularies 'https://example.org/ns#' source 'https://example.org/ex.ttl'", $violations);
        $this->assertStringContainsString("\$import 'https://example.invalid/settings.json'", $violations);
        $this->assertSame(4, substr_count($violations, 'is not a relative path inside the blueprint'));
        // relative sources are still resolved; a skipped import contributes nothing
        $this->assertSame($this->dir . '/modules/Local-1.0.0.zip', $blueprint->modules()[1]['source']);
        $this->assertSame([], $blueprint->settings());
    }

    public function testSelfContainedAcceptsRelativeSourcesAndImports(): void
    {
        $this->writeIn('shared/modules.json', '[ { "name": "A", "source": "../modules/A-1.0.0.zip" } ]');
        $base = $this->write('base.json', json_encode([
            'x-omeka-s-cli' => ['install' => ['source' => './core/omeka-s-4.1.1.zip']],
            'modules' => [['$import' => './shared/modules.json']],
            'files' => [['source' => './files/logo.png', 'destination' => 'files/logo.png']],
        ]));

        $loader = new BlueprintLoader(null, null, true);
        $blueprint = $loader->load($base);

        $this->assertSame([], $loader->takeViolations());
        $this->assertSame($this->dir . '/modules/A-1.0.0.zip', $blueprint->modules()[0]['source']);
    }

    public function testExtensionItemSourcesResolveAgainstTheDeclaringFile(): void
    {
        $path = $this->writeIn('bp/blueprint.json', json_encode(['x-omeka-s-cli' => [
            'itemSets' => [['source' => './content/set.jsonld', 'title' => 'Set']],
            'items' => [[
                'source' => './content/a.jsonld',
                'media' => [
                    ['ingester' => 'upload', 'source' => './media/a.png'],
                    ['ingester' => 'iiif', 'source' => 'https://example.org/iiif/info.json'],
                    ['ingester' => 'html', 'source' => '<p>not a path</p>'],
                ],
            ]],
        ]]));

        $blueprint = (new BlueprintLoader())->load($path);
        $items = $blueprint->extensionItems();

        $this->assertSame($this->dir . '/bp/content/a.jsonld', $items[0]['source']);
        $this->assertSame($this->dir . '/bp/media/a.png', $items[0]['media'][0]['source']);
        $this->assertSame('https://example.org/iiif/info.json', $items[0]['media'][1]['source']);
        $this->assertSame('<p>not a path</p>', $items[0]['media'][2]['source']);
        $this->assertSame($this->dir . '/bp/content/set.jsonld', $blueprint->extensionItemSets()[0]['source']);
    }

    public function testSimpleMediaRelativeUrlResolvesAndAbsoluteUrlStays(): void
    {
        $path = $this->writeIn('bp/blueprint.json', json_encode([
            'itemSets' => [['title' => 'Paintings']],
            'items' => [['title' => 'Mona Lisa', 'media' => [
                ['type' => 'url', 'url' => './m.png'],
                ['type' => 'url', 'url' => 'https://x.org/a.jpg'],
            ]]],
        ]));

        $blueprint = (new BlueprintLoader())->load($path);

        $this->assertSame($this->dir . '/bp/m.png', $blueprint->items()[0]['media'][0]['url']);
        $this->assertSame('https://x.org/a.jpg', $blueprint->items()[0]['media'][1]['url']);
        $this->assertSame('Paintings', $blueprint->itemSets()[0]['title']);
    }

    public function testTheLoaderRecordsWhichMediaAreFiles(): void
    {
        $path = $this->writeIn('bp/blueprint.json', json_encode([
            'items' => [['title' => 'A', 'media' => [['url' => './m.png'], ['url' => 'https://x.org/a.jpg']]]],
            'x-omeka-s-cli' => ['items' => [['source' => './a.jsonld', 'media' => [['ingester' => 'upload', 'source' => './u.png']]]]],
        ]));

        $blueprint = (new BlueprintLoader())->load($path);

        $this->assertTrue($blueprint->isFileSource($this->dir . '/bp/m.png'));
        $this->assertTrue($blueprint->isFileSource($this->dir . '/bp/u.png'));
        $this->assertFalse($blueprint->isFileSource('https://x.org/a.jpg'));
    }

    public function testSelfContainedFlagsUploadUrlsButNotLinkMedia(): void
    {
        $base = $this->write('base.json', json_encode([
            'x-omeka-s-cli' => [
                'install' => ['source' => './core/omeka-s-4.1.1.zip'],
                'items' => [[
                    'source' => './content/a.jsonld',
                    'media' => [
                        ['ingester' => 'upload', 'source' => 'https://example.org/a.png'],
                        ['ingester' => 'iiif', 'source' => 'https://example.org/iiif/info.json'],
                    ],
                ]],
            ],
            'items' => [['title' => 'B', 'media' => [['type' => 'url', 'url' => 'https://example.org/b.jpg']]]],
        ]));

        $loader = new BlueprintLoader(null, null, true);
        $loader->load($base);
        $violations = $loader->takeViolations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("x-omeka-s-cli.items[0] media[0].source 'https://example.org/a.png'", $violations[0]);
    }

    public function testSelfContainedFlagsARemoteItemSource(): void
    {
        $base = $this->write('base.json', json_encode(['x-omeka-s-cli' => [
            'install' => ['source' => './core/omeka-s-4.1.1.zip'],
            'itemSets' => [['source' => 'https://example.org/set.jsonld']],
        ]]));

        $loader = new BlueprintLoader(null, null, true);
        $loader->load($base);

        $this->assertSame(
            ["x-omeka-s-cli.itemSets[0] source 'https://example.org/set.jsonld' in '{$base}' is not a relative path inside the blueprint."],
            $loader->takeViolations()
        );
    }

    public function testViolationsAreOnlyCollectedInSelfContainedMode(): void
    {
        $base = $this->write('base.json', '{ "modules": [ { "name": "Log", "source": "gh:owner/log" } ] }');

        $loader = new BlueprintLoader();
        $loader->load($base);

        $this->assertSame([], $loader->takeViolations());
    }

    public function testSelfContainedStillRejectsAReferenceOutsideTheRoot(): void
    {
        mkdir($this->dir . '/site');
        $base = $this->writeIn('site/base.json', '{ "files": [ { "source": "../outside.txt", "destination": "files/x" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('outside the root');
        (new BlueprintLoader(null, null, true))->load($base);
    }

    /** A loader whose gh:fake/repo:<path> references are files under the temp dir (an import by gh:). */
    private function loaderWithFakeRepository(): BlueprintLoader
    {
        $raw = 'https://raw.githubusercontent.com/fake/repo/HEAD/';
        return new BlueprintLoader(fn(Location $location): string => str_starts_with($location->value(), $raw)
            ? (string) file_get_contents($this->dir . '/' . substr($location->value(), strlen($raw)))
            : ResourceFetcher::fetch($location));
    }

    private function files(string $source): string
    {
        return $this->write('base.jsonc', json_encode(['files' => [['source' => $source, 'destination' => 'config/a.php']]]));
    }

    public function testAFileImportedByRepositoryReferenceIsItsOwnRoot(): void
    {
        // inside the blueprint's folder, but outside the folder of the file imported by gh:
        $this->writeIn('lists/modules.json', json_encode([['name' => 'M', 'source' => '../zips/M.zip']]));
        $base = $this->write('base.jsonc', json_encode(['modules' => [['$import' => 'gh:fake/repo:lists/modules.json']]]));

        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('outside the root');
        $this->loaderWithFakeRepository()->load($base);
    }

    public function testAFileImportedByRelativePathKeepsTheBlueprintRoot(): void
    {
        $this->writeIn('lists/modules.json', json_encode([['name' => 'M', 'source' => '../zips/M.zip']]));
        $base = $this->write('base.jsonc', json_encode(['modules' => [['$import' => './lists/modules.json']]]));

        $modules = (new BlueprintLoader())->load($base)->modules();
        $this->assertSame($this->dir . '/zips/M.zip', $modules[0]['source']);
    }

    public function testAnEmptySourceIsLeftToTheSchema(): void
    {
        // blueprint:export writes "source": "" for a vocabulary it cannot locate; the schema decides
        // (v0.1.0 accepts it), and nothing is fetched
        $files = (new BlueprintLoader())->load($this->files(''))->files();
        $this->assertSame('', $files[0]['source']);
    }

    public function testAnEmptyImportIsRejected(): void
    {
        $base = $this->write('base.jsonc', json_encode(['modules' => [['$import' => '']]]));
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('is empty');
        (new BlueprintLoader())->load($base);
    }

    public function testASourceIsNotTrimmed(): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('starts with whitespace');
        (new BlueprintLoader())->load($this->files(' ./config/a.php'));
    }

    public function testAPercentEncodedPathIsDecoded(): void
    {
        $files = (new BlueprintLoader())->load($this->files('./My%20File.php'))->files();
        $this->assertSame($this->dir . '/My File.php', $files[0]['source']);
    }

    public function testAnUnencodedHashInALocalPathIsRejected(): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('%23');
        (new BlueprintLoader())->load($this->files('./notes#1.php'));
    }

    public function testARepositoryIsRejectedWhereAFileIsNeeded(): void
    {
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('git repository');
        (new BlueprintLoader())->load($this->files('gh:owner/repo'));
    }

    public function testAnAddonSourceThatLooksLikeIdVersionIsRejected(): void
    {
        // 'CleanUrl:3.4' is not a source: name and version are their own fields
        $base = $this->write('base.jsonc', json_encode(['modules' => [['name' => 'CleanUrl', 'source' => 'CleanUrl:3.4']]]));
        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('URI scheme');
        (new BlueprintLoader())->load($base);
    }

    public function testAddonSourcesByForm(): void
    {
        $base = $this->write('base.jsonc', json_encode(['modules' => [
            ['name' => 'A', 'source' => 'gh:o/a#v2'],
            ['name' => 'B', 'source' => 'https://github.com/o/b'],
            ['name' => 'C', 'source' => 'git@gitlab.com:g/c.git'],
            ['name' => 'D', 'source' => 'https://example.org/zips/D.zip'],
            ['name' => 'E', 'source' => 'gh:o/e@v1:dist/E.zip'],
            ['name' => 'F', 'source' => './zips/F.zip'],
        ]]));
        $sources = array_column((new BlueprintLoader())->load($base)->modules(), 'source', 'name');
        $this->assertSame([
            'A' => 'https://github.com/o/a.git#v2',
            'B' => 'https://github.com/o/b.git',
            'C' => 'git@gitlab.com:g/c.git',
            'D' => 'https://example.org/zips/D.zip',
            'E' => 'https://raw.githubusercontent.com/o/e/v1/dist/E.zip',
            'F' => $this->dir . '/zips/F.zip',
        ], $sources);
    }

    /** A validator that records what it was given and reports $errors for the documents of type $failType. */
    private function recordingValidator(array &$calls, string|false|null $failType = false, array $errors = ['/0: bad']): DocumentValidator
    {
        return new class ($calls, $failType, $errors) implements DocumentValidator {
            public function __construct(private array &$calls, private string|false|null $failType, private array $errors)
            {
            }
            public function validateDocument(mixed $data, ?string $type): array
            {
                $this->calls[] = [$type, $data];
                return $type === $this->failType ? $this->errors : [];
            }
        };
    }

    public function testValidatesEachDocumentAsWritten(): void
    {
        $this->writeIn('lists/modules.json', json_encode([['name' => 'M', 'source' => './zips/M.zip']]));
        $this->writeIn('lists/settings.json', json_encode(['a' => 1]));
        $blueprint = ['modules' => [['$import' => './lists/modules.json']], 'settings' => [['$import' => './lists/settings.json']]];
        $base = $this->write('base.jsonc', json_encode($blueprint));

        $calls = [];
        (new BlueprintLoader(null, null, false, $this->recordingValidator($calls)))->load($base);

        $this->assertSame([
            [null, $blueprint],
            ['modules', [['name' => 'M', 'source' => './zips/M.zip']]],
            ['settings', ['a' => 1]],
        ], $calls);
    }

    public function testASingleImportedEntryIsValidatedAsAList(): void
    {
        $this->writeIn('lists/module.json', json_encode(['name' => 'M']));
        $base = $this->write('base.jsonc', json_encode(['modules' => [['$import' => './lists/module.json']]]));

        $calls = [];
        (new BlueprintLoader(null, null, false, $this->recordingValidator($calls)))->load($base);

        $this->assertSame(['modules', [['name' => 'M']]], $calls[1]);
    }

    public function testAnInvalidImportIsReportedWithItsLabelAndNotFollowed(): void
    {
        // the invalid list's own import points at a file that does not exist: following it would throw
        $this->writeIn('lists/modules.json', json_encode([['nme' => 'M'], ['$import' => './missing.json']]));
        $base = $this->write('base.jsonc', json_encode(['modules' => ['A', ['$import' => './lists/modules.json']]]));

        $calls = [];
        $loader = new BlueprintLoader(null, null, false, $this->recordingValidator($calls, 'modules'));
        $blueprint = $loader->load($base);

        $this->assertSame(['lists/modules.json: /0: bad'], $loader->takeErrors());
        $this->assertSame(['A'], $blueprint->modules());
        $this->assertSame([], $loader->takeErrors(), 'errors are cleared on read');
    }

    public function testAnInvalidBlueprintIsNotResolved(): void
    {
        $base = $this->write('base.jsonc', json_encode(['files' => [['source' => './a.php', 'destination' => 'a.php']], 'modules' => [['$import' => './missing.json']]]));

        $calls = [];
        $loader = new BlueprintLoader(null, null, false, $this->recordingValidator($calls, null, ['/files/0: bad']));
        $blueprint = $loader->load($base);

        $this->assertSame(['base.jsonc: /files/0: bad'], $loader->takeErrors());
        $this->assertSame('./a.php', $blueprint->files()[0]['source']);
    }

    public function testAPartialIsValidatedAsWritten(): void
    {
        $partial = $this->write('modules.json', json_encode([['name' => 'M', 'source' => './zips/M.zip']]));

        $calls = [];
        (new BlueprintLoader(null, null, false, $this->recordingValidator($calls)))->loadPartial($partial, 'modules');

        $this->assertSame([['modules', [['name' => 'M', 'source' => './zips/M.zip']]]], $calls);
    }
}
