<?php
namespace Tests\Blueprint\Bundle;

use Closure;
use Exception;
use LogicException;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\BlueprintValidator;
use OSC\Blueprint\Bundle\AddonPackager;
use OSC\Blueprint\Bundle\Bundler;
use OSC\Blueprint\Bundle\PackagedAddon;
use OSC\Blueprint\Bundle\VocabularyFormat;
use OSC\Blueprint\SelfContainedCheck;
use OSC\Blueprint\SourceFields;
use OSC\Downloader\AddonResolver;
use OSC\Downloader\ResolvedAddon;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\Path;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUri;
use OSC\Helper\VersionCompatibility;
use OSC\Manager\AbstractManager;
use OSC\Manager\Result;
use OSC\Omeka\OmekaDotOrgApi;
use OSC\Repository\Module\ModuleDetails;
use OSC\Repository\Module\ModuleVersion;
use OSC\Repository\RepositoryInterface;
use OSC\Repository\Theme\ThemeDetails;
use OSC\Repository\Theme\ThemeVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(Bundler::class)]
#[UsesClass(Blueprint::class)]
#[UsesClass(BlueprintLoader::class)]
#[UsesClass(BlueprintValidator::class)]
#[UsesClass(AddonPackager::class)]
#[UsesClass(PackagedAddon::class)]
#[UsesClass(VocabularyFormat::class)]
#[UsesClass(SelfContainedCheck::class)]
#[UsesClass(SourceFields::class)]
#[UsesClass(AddonResolver::class)]
#[UsesClass(ResolvedAddon::class)]
#[UsesClass(ZipDownloader::class)]
#[UsesClass(Path::class)]
#[UsesClass(ReferenceResolver::class)]
#[UsesClass(ResourceFetcher::class)]
#[UsesClass(ResourceUriParser::class)]
#[UsesClass(ResourceUri::class)]
#[UsesClass(VersionCompatibility::class)]
#[UsesClass(OmekaDotOrgApi::class)]
#[UsesClass(Result::class)]
#[UsesClass(ModuleDetails::class)]
#[UsesClass(ModuleVersion::class)]
#[UsesClass(ThemeDetails::class)]
#[UsesClass(ThemeVersion::class)]
class BundlerTest extends TestCase
{
    private const CORE_URL = 'https://github.com/omeka/omeka-s/releases/download/v4.1.1/omeka-s-4.1.1.zip';

    private string $dir;

    /** @var string[] Every source the fake download was asked for */
    private array $downloads = [];

    /** @var array<string,string[]> The request headers of each download, by source */
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp_bundler_' . uniqid();
        mkdir("{$this->dir}/fixtures", 0777, true);
        mkdir("{$this->dir}/out");
        $this->downloads = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    private function zip(string $name, array $entries): string
    {
        $path = "{$this->dir}/fixtures/{$name}";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return $path;
    }

    private function moduleZip(string $namespace, string $version): string
    {
        return $this->zip("{$namespace}-{$version}.zip", [
            "{$namespace}/config/module.ini" => "[info]\nversion = \"{$version}\"\nomeka_version_constraint = \"^4.0\"\n",
            "{$namespace}/Module.php" => "<?php\nnamespace {$namespace};\n",
        ]);
    }

    private function themeZip(string $folder, string $label, string $version): string
    {
        return $this->zip("{$folder}.zip", [
            "{$folder}/config/theme.ini" => "[info]\nname = \"{$label}\"\nversion = \"{$version}\"\nomeka_version_constraint = \"^4.0\"\n",
        ]);
    }

    /** @param list<ModuleDetails|ThemeDetails> $catalog */
    private function resolver(string $kind, array $catalog): AddonResolver
    {
        $manager = $this->createMock(AbstractManager::class);
        $manager->method('find')->willReturnCallback(function (string $id) use ($catalog) {
            foreach ($catalog as $details) {
                if (strcasecmp($details->getDirname(), $id) === 0) {
                    return new Result($details, $this->createMock(RepositoryInterface::class));
                }
            }
            return null;
        });
        return new AddonResolver($manager, $kind);
    }

    /**
     * A bundler over a catalog with Common 1.0.0 and the freedom theme, whose downloads come from
     * $remote (url => [content, content type]) or are local copies.
     */
    private function bundler(array $remote = [], ?Closure $latest = null): Bundler
    {
        $common = $this->moduleZip('Common', '1.0.0');
        $freedom = $this->themeZip('freedom-v1.0.7', 'Freedom', '1.0.7');
        $remote += [self::CORE_URL => ['core zip', 'application/zip']];

        return new Bundler(
            $this->resolver('module', [new ModuleDetails('Common', 'Common', '1.0.0', [
                '1.0.0' => new ModuleVersion('1.0.0', '2024-01-01', $common, '^4.0'),
            ])]),
            $this->resolver('theme', [new ThemeDetails('Freedom', 'freedom', '1.0.7', [
                '1.0.7' => new ThemeVersion('1.0.7', '2024-01-01', $freedom, '^4.0'),
            ])]),
            new AddonPackager(),
            $latest ?? fn() => throw new LogicException('the latest Omeka S version must not be looked up'),
            function (string $source, string $target, array $headers = []) use ($remote): ?string {
                $this->downloads[] = $source;
                $this->headers[$source] = $headers;
                if (isset($remote[$source])) {
                    file_put_contents($target, $remote[$source][0]);
                    return $remote[$source][1];
                }
                if (is_file($source)) {
                    copy($source, $target);
                    return null;
                }
                throw new Exception("not found: {$source}");
            },
        );
    }

    private function bundled(string $out = 'out'): array
    {
        return json_decode(file_get_contents("{$this->dir}/{$out}/blueprint.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The full example: every kind of source, a renamed theme referenced by a site, two clashing files. */
    private function fullBlueprint(): Blueprint
    {
        return new Blueprint([
            'preferredVersions' => ['omeka' => '4.1.1'],
            'install' => ['title' => 'Demo'],
            'modules' => ['common', ['name' => 'Local', 'source' => $this->moduleZip('Local', '2.0.0'), 'state' => 'install']],
            'themes' => ['default', ['name' => 'Freedom']],
            'vocabularies' => [[
                'prefix' => 'ex', 'namespaceUri' => 'https://example.org/ns#', 'label' => 'Ex',
                'source' => 'https://example.org/ex.ttl',
            ]],
            'resourceTemplates' => [['source' => 'https://example.org/templates/book']],
            'files' => [
                ['source' => 'https://example.org/a/logo.png', 'destination' => 'files/a.png'],
                ['source' => 'https://example.org/b/logo.png', 'destination' => 'files/b.png'],
            ],
            'sites' => [['title' => 'Demo', 'slug' => 'demo', 'theme' => 'Freedom']],
            'settings' => ['installation_title' => 'Demo'],
        ]);
    }

    private function fullRemote(): array
    {
        return [
            'https://example.org/ex.ttl' => ['@prefix ex: <https://example.org/ns#> .', 'text/turtle'],
            'https://example.org/templates/book' => ['{"o:label": "Book"}', 'application/json'],
            'https://example.org/a/logo.png' => ['png-a', 'image/png'],
            'https://example.org/b/logo.png' => ['png-b', 'image/png'],
        ];
    }

    public function testBundlesEverySourceAndRewritesItRelative(): void
    {
        $warnings = $this->bundler($this->fullRemote())->bundle($this->fullBlueprint(), "{$this->dir}/out");
        $json = $this->bundled();

        $this->assertSame(BlueprintValidator::SCHEMA_ID, $json['$schema']);
        $this->assertSame(['title' => 'Demo'], $json['install']);
        $this->assertSame(['install' => ['source' => './core/omeka-s-4.1.1.zip']], $json['x-omeka-s-cli']);
        $this->assertContains(self::CORE_URL, $this->downloads);
        $this->assertEquals([
            ['name' => 'Common', 'version' => '1.0.0', 'source' => './modules/Common-1.0.0.zip'],
            ['name' => 'Local', 'source' => './modules/Local-2.0.0.zip', 'state' => 'install', 'version' => '2.0.0'],
        ], $json['modules']);
        $this->assertEquals(['default', ['name' => 'freedom', 'version' => '1.0.7', 'source' => './themes/freedom-1.0.7.zip']], $json['themes']);
        $this->assertSame('freedom', $json['sites'][0]['theme']);
        $this->assertSame('./vocabularies/ex.ttl', $json['vocabularies'][0]['source']);
        $this->assertSame('turtle', $json['vocabularies'][0]['format']);
        $this->assertSame('./resource-templates/book.json', $json['resourceTemplates'][0]['source']);
        $this->assertSame(['./files/logo.png', './files/logo-2.png'], array_column($json['files'], 'source'));
        $this->assertSame(['installation_title' => 'Demo'], $json['settings']);
        foreach (['core/omeka-s-4.1.1.zip', 'modules/Common-1.0.0.zip', 'modules/Local-2.0.0.zip', 'themes/freedom-1.0.7.zip',
                  'vocabularies/ex.ttl', 'resource-templates/book.json', 'files/logo.png', 'files/logo-2.png'] as $file) {
            $this->assertFileExists("{$this->dir}/out/{$file}");
        }
        $this->assertSame('png-b', file_get_contents("{$this->dir}/out/files/logo-2.png"));
        $this->assertContains("modules[0] 'common': renamed to 'Common', its folder name", $warnings);
        $this->assertContains("themes[1] 'Freedom': renamed to 'freedom', its folder name", $warnings);
    }

    public function testTheBundleIsSelfContainedAndValid(): void
    {
        $this->bundler($this->fullRemote())->bundle($this->fullBlueprint(), "{$this->dir}/out");

        $loader = new BlueprintLoader(null, null, true);
        $bundled = $loader->load("{$this->dir}/out/blueprint.json");

        $this->assertSame([], $loader->takeViolations());
        $this->assertSame([], SelfContainedCheck::violations($bundled, true));
        $this->assertSame([], (new BlueprintValidator(BlueprintValidator::bundledSchemaPath()))->validateBlueprint($bundled->toArray()));
    }

    public function testRebundlingABundleIsStable(): void
    {
        $this->bundler($this->fullRemote())->bundle($this->fullBlueprint(), "{$this->dir}/out");
        mkdir("{$this->dir}/again");

        $first = (new BlueprintLoader())->load("{$this->dir}/out/blueprint.json");
        $this->bundler()->bundle($first, "{$this->dir}/again");

        $this->assertEquals($this->bundled('out'), $this->bundled('again'));
    }

    public function testPinsTheLatestOmekaVersionWhenTheBlueprintHasNone(): void
    {
        $this->bundler([], fn() => '4.1.1')->bundle(new Blueprint(['modules' => ['Common']]), "{$this->dir}/out");

        $this->assertSame('4.1.1', $this->bundled()['preferredVersions']['omeka']);
    }

    public function testRejectsADevCore(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("preferredVersions.omeka is 'dev'");
        $this->bundler()->bundle(new Blueprint(['preferredVersions' => ['omeka' => 'dev']]), "{$this->dir}/out");
    }

    public function testAGitSourceIsRefusedBeforeAnythingIsDownloaded(): void
    {
        try {
            $this->bundler()->bundle(new Blueprint([
                'preferredVersions' => ['omeka' => '4.1.1'],
                'modules' => ['Common', ['name' => 'CustomVocab', 'source' => 'gh:omeka-s-modules/CustomVocab']],
            ]), "{$this->dir}/out");
            $this->fail('a git source must be refused');
        } catch (Exception $e) {
            $this->assertSame(
                "modules[1] 'CustomVocab': a git source can't be bundled; a bundle holds release ZIPs. "
                . 'Point source to a release ZIP (URL or path).',
                $e->getMessage()
            );
        }
        $this->assertSame([], $this->downloads);
    }

    public function testErrorsNameTheEntry(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("modules[1] 'Nope': Could not find module 'Nope' in any repository.");
        $this->bundler()->bundle(new Blueprint([
            'preferredVersions' => ['omeka' => '4.1.1'],
            'modules' => ['Common', 'Nope'],
        ]), "{$this->dir}/out");
    }

    public function testEmptyObjectsStayObjects(): void
    {
        $this->bundler()->bundle(new Blueprint(['preferredVersions' => ['omeka' => '4.1.1'], 'settings' => [], 'meta' => []]), "{$this->dir}/out");

        $raw = file_get_contents("{$this->dir}/out/blueprint.json");
        $this->assertStringContainsString('"settings": {}', $raw);
        $this->assertStringContainsString('"meta": {}', $raw);
    }

    public function testAnExistingInstallSourceIsCopied(): void
    {
        $core = $this->zip('my-core.zip', ['omeka-s/application/Module.php' => '<?php']);
        $this->bundler()->bundle(new Blueprint([
            'preferredVersions' => ['omeka' => '4.1.1'],
            'x-omeka-s-cli' => ['install' => ['source' => $core], 'other' => 'kept'],
        ]), "{$this->dir}/out");

        $this->assertSame(['install' => ['source' => './core/my-core.zip'], 'other' => 'kept'], $this->bundled()['x-omeka-s-cli']);
        $this->assertNotContains(self::CORE_URL, $this->downloads);
    }

    public function testAnExplicitVocabularyFormatIsKept(): void
    {
        $this->bundler(['https://example.org/v' => ['<rdf/>', 'text/plain']])->bundle(new Blueprint([
            'preferredVersions' => ['omeka' => '4.1.1'],
            'vocabularies' => [['prefix' => 'v', 'namespaceUri' => 'https://example.org/v#', 'label' => 'V',
                                'source' => 'https://example.org/v', 'format' => 'rdfxml']],
        ]), "{$this->dir}/out");

        $vocabulary = $this->bundled()['vocabularies'][0];
        $this->assertSame('rdfxml', $vocabulary['format']);
        $this->assertSame('./vocabularies/v.rdf', $vocabulary['source']);
    }

    private function vocabularyBlueprint(string $source): Blueprint
    {
        return new Blueprint([
            'preferredVersions' => ['omeka' => '4.1.1'],
            'vocabularies' => [['prefix' => 'ex', 'namespaceUri' => 'https://example.org/ns#', 'label' => 'Ex', 'source' => $source]],
        ]);
    }

    public function testVocabulariesAreRequestedAsRdf(): void
    {
        $this->bundler(['https://example.org/ns' => ['<rdf/>', 'application/rdf+xml']])
            ->bundle($this->vocabularyBlueprint('https://example.org/ns'), "{$this->dir}/out");

        $accept = implode("\n", $this->headers['https://example.org/ns'] ?? []);
        $this->assertStringContainsString('Accept: text/turtle', $accept);
        $this->assertStringContainsString('application/rdf+xml', $accept);
    }

    public function testAVocabularyServedAsHtmlIsRefused(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("vocabularies[0] 'ex': https://example.org/ns returned an HTML page, not RDF");
        $this->bundler(['https://example.org/ns' => ['<html></html>', 'text/html']])
            ->bundle($this->vocabularyBlueprint('https://example.org/ns'), "{$this->dir}/out");
    }

    public function testAnUndetectedVocabularyFormatStaysUndetectedWhenRebundled(): void
    {
        $this->bundler(['https://example.org/ns' => ['@prefix ex: <https://example.org/ns#> .', 'text/plain']])
            ->bundle($this->vocabularyBlueprint('https://example.org/ns'), "{$this->dir}/out");
        mkdir("{$this->dir}/again");
        $this->bundler()->bundle((new BlueprintLoader())->load("{$this->dir}/out/blueprint.json"), "{$this->dir}/again");

        $this->assertArrayNotHasKey('format', $this->bundled('out')['vocabularies'][0]);
        $this->assertEquals($this->bundled('out'), $this->bundled('again'));
    }
}
