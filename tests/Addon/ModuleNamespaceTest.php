<?php
namespace Tests\Addon;

use OSC\Addon\ModuleNamespace;
use OSC\Exceptions\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleNamespace::class)]
class ModuleNamespaceTest extends TestCase
{
    public function testReadsTheNamespace(): void
    {
        $this->assertSame('Foo', ModuleNamespace::fromModulePhp("<?php\nnamespace Foo;\n\nclass Module {}\n"));
    }

    public function testReadsANestedNamespaceWithExtraWhitespace(): void
    {
        $this->assertSame('Foo\\Bar', ModuleNamespace::fromModulePhp("<?php\n\nnamespace   Foo\\Bar ;\n"));
    }

    public function testNoNamespaceIsNull(): void
    {
        $this->assertNull(ModuleNamespace::fromModulePhp("<?php\nclass Module {}\n"));
    }

    public function testRequireReturnsTheNamespace(): void
    {
        $this->assertSame('Foo', ModuleNamespace::requireFromModulePhp("<?php\nnamespace Foo;\n"));
    }

    public function testRequireOfAModulePhpWithoutNamespaceIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No namespace found in Module.php.');
        ModuleNamespace::requireFromModulePhp('');
    }
}
