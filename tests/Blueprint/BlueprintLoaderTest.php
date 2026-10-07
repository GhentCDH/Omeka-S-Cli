<?php
namespace Tests\Blueprint;

use Exception;
use OSC\Blueprint\BlueprintLoader;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\Reference\RepoProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintLoader::class)]
#[UsesClass(ReferenceResolver::class)]
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

    public function testRoutesSourceAndImportsThroughTheInjectedResolver(): void
    {
        // a fake provider maps test:<name> to a file in the temp dir, proving both the top-level
        // source and a nested $import are resolved through the injected ReferenceResolver
        $provider = new class ($this->dir) implements RepoProvider {
            public function __construct(private string $dir)
            {
            }
            public function supports(string $reference): bool
            {
                return str_starts_with($reference, 'test:');
            }
            public function toRawUrl(string $reference): string
            {
                return $this->dir . '/' . substr($reference, 5);
            }
        };
        $this->write('more.jsonc', '["B"]');
        $this->write('base.jsonc', '{ "modules": ["A", { "$import": "test:more.jsonc" }] }');

        $loader = new BlueprintLoader(new ReferenceResolver([$provider]));
        $blueprint = $loader->load('test:base.jsonc');

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
        $this->expectExceptionMessageMatches('/file: URL/');
        (new BlueprintLoader())->load($base);
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
        $this->expectExceptionMessageMatches('/outside the blueprint root/');
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
        $this->expectExceptionMessageMatches('/outside the blueprint root/');
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

    public function testNonPathAddonSourcesAreLeftAsIs(): void
    {
        $sources = [
            'gh:Daniel-KM/Omeka-S-module-Common#3.4.71',
            'https://github.com/Daniel-KM/Omeka-S-module-Log.git',
            'git@github.com:Daniel-KM/Omeka-S-module-Log.git',
            'https://example.org/AdvancedSearch-3.4.22.zip',
        ];
        $base = $this->write('base.jsonc', json_encode([
            'modules' => array_map(fn($s, $i) => ['name' => "M{$i}", 'source' => $s], $sources, array_keys($sources)),
        ]));

        $this->assertSame($sources, array_column((new BlueprintLoader())->load($base)->modules(), 'source'));
    }

    public function testAbsoluteAddonSourceIsRejected(): void
    {
        $base = $this->write('base.jsonc', '{ "modules": [ { "name": "Common", "source": "/tmp/Common.zip" } ] }');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/absolute path/');
        (new BlueprintLoader())->load($base);
    }
}
