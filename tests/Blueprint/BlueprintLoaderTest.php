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
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    public function testResolvesImportUnderTheKeyAndDeduplicatesLastWins(): void
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
        $modules = $blueprint->modules();

        // A (deduped to the last, object form), B, C
        $this->assertCount(3, $modules);

        $byName = [];
        foreach ($modules as $m) {
            $byName[is_string($m) ? $m : $m['name']] = $m;
        }
        $this->assertArrayHasKey('A', $byName);
        $this->assertArrayHasKey('B', $byName);
        $this->assertArrayHasKey('C', $byName);
        $this->assertSame(['name' => 'A', 'state' => 'install'], $byName['A']);
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
}
