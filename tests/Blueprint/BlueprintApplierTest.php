<?php
namespace Tests\Blueprint;

use OSC\Blueprint\BlueprintApplier;
use OSC\Commands\AbstractCommand;
use OSC\Helper\Reference\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(BlueprintApplier::class)]
#[UsesClass(ReferenceResolver::class)]
class BlueprintApplierTest extends TestCase
{
    private function resolveAssetPath(?string $baseSource, ?string $path): ?string
    {
        $command = $this->createMock(AbstractCommand::class);
        $applier = new BlueprintApplier($command, false, false, [], $baseSource);
        $method = new ReflectionMethod(BlueprintApplier::class, 'resolveAssetPath');
        return $method->invoke($applier, $path);
    }

    public function testResolvesRelativePathAgainstLocalBase(): void
    {
        $this->assertSame(
            '/tmp/dir/logo.png',
            $this->resolveAssetPath('/tmp/dir/site.blueprint.jsonc', 'logo.png')
        );
    }

    public function testNormalizesParentTraversalAgainstUrlBase(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/logo.png',
            $this->resolveAssetPath('https://raw.githubusercontent.com/owner/repo/main/dir/site.jsonc', '../logo.png')
        );
    }

    public function testConvertsBlobUrlBaseToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/dir/logo.png',
            $this->resolveAssetPath('https://github.com/owner/repo/blob/main/dir/site.jsonc', 'logo.png')
        );
    }

    public function testAbsoluteUrlPassesThrough(): void
    {
        $url = 'https://cdn.example.org/logo.png';
        $this->assertSame($url, $this->resolveAssetPath('/tmp/dir/site.jsonc', $url));
    }

    public function testNullAndEmptyPathReturnedAsIs(): void
    {
        $this->assertNull($this->resolveAssetPath('/tmp/dir/site.jsonc', null));
        $this->assertSame('', $this->resolveAssetPath('/tmp/dir/site.jsonc', ''));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $command = $this->createMock(AbstractCommand::class);
        $applier = new BlueprintApplier($command, false, false, [], '/tmp/dir/site.jsonc');
        return (new ReflectionMethod(BlueprintApplier::class, $method))->invoke($applier, ...$args);
    }

    public function testAddonWithoutSourceResolvesByNameAndVersion(): void
    {
        $this->assertSame('Common', $this->invoke('addonUri', ['name' => 'Common']));
        $this->assertSame('Common:3.4.60', $this->invoke('addonUri', ['name' => 'Common', 'version' => '3.4.60']));
    }

    public function testAddonSourceStringIsPassedThrough(): void
    {
        $zip = 'https://example.org/Mapping-2.1.0.zip';
        // a ZIP URL pins the release, so it wins over version
        $this->assertSame($zip, $this->invoke('addonUri', ['name' => 'Mapping', 'source' => $zip, 'version' => '2.0.0']));
        $this->assertSame('gh:owner/repo', $this->invoke('addonUri', ['name' => 'X', 'source' => 'gh:owner/repo']));
    }

    public function testVersionSelectsTheTagOfAGitSource(): void
    {
        $this->assertSame('gh:owner/repo#1.2.0', $this->invoke('addonUri', ['name' => 'X', 'source' => 'gh:owner/repo', 'version' => '1.2.0']));
        $this->assertSame(
            'https://example.org/owner/repo.git#1.2.0',
            $this->invoke('addonUri', ['name' => 'X', 'source' => 'https://example.org/owner/repo.git', 'version' => '1.2.0'])
        );
        // a ref already in the source wins
        $this->assertSame('gh:owner/repo#main', $this->invoke('addonUri', ['name' => 'X', 'source' => 'gh:owner/repo#main', 'version' => '1.2.0']));
    }

    public function testVocabularySourceMapsToTheImporterFileOrUrl(): void
    {
        $base = ['prefix' => 'ex', 'namespaceUri' => 'https://ex.org/', 'label' => 'Ex'];
        $this->assertSame(
            $base + ['file' => '/tmp/dir/ex.rdf'],
            $this->invoke('vocabularyConfig', $base + ['source' => 'ex.rdf'])
        );
        $this->assertSame(
            $base + ['url' => 'https://ex.org/ex.rdf'],
            $this->invoke('vocabularyConfig', $base + ['source' => 'https://ex.org/ex.rdf'])
        );
    }

    public function testFileDestinationMustStayInsideTheOmekaRoot(): void
    {
        $this->assertSame('config/cleanurl.config.php', $this->invoke('safeDestination', 'config/cleanurl.config.php'));
        $this->assertSame('files/..hidden', $this->invoke('safeDestination', 'files/..hidden'));
        foreach (['', '/etc/passwd', '../x', 'config/../../x', 'config\\..\\x', 'C:/x', 'files/..'] as $unsafe) {
            try {
                $this->invoke('safeDestination', $unsafe);
                $this->fail("accepted unsafe destination '{$unsafe}'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
