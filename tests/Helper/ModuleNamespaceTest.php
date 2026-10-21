<?php
namespace Tests\Helper;

use OSC\Helper\ModuleNamespace;
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
}
