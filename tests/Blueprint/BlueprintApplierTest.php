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
}
