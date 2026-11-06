<?php
namespace Tests\Helper;

use OSC\Helper\ModuleDependencyOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleDependencyOrder::class)]
class ModuleDependencyOrderTest extends TestCase
{
    public function testSortPutsDependenciesFirst(): void
    {
        $this->assertEquals(
            ['Common', 'Log', 'IiifServer'],
            ModuleDependencyOrder::sort(['IiifServer', 'Log', 'Common'])
        );
    }

    public function testReverseSortPutsDependenciesLast(): void
    {
        $this->assertEquals(
            ['IiifServer', 'Log', 'Common'],
            ModuleDependencyOrder::reverseSort(['Common', 'Log', 'IiifServer'])
        );
    }

    public function testUnknownModulesComeAfterKnownOnes(): void
    {
        $sorted = ModuleDependencyOrder::sort(['ValueSuggest', 'Common', 'BulkEdit', 'Log']);

        $this->assertEquals(['Common', 'Log', 'ValueSuggest', 'BulkEdit'], $sorted);
    }

    public function testUnknownModulesComeFirstWhenReversed(): void
    {
        $sorted = ModuleDependencyOrder::reverseSort(['ValueSuggest', 'Common', 'BulkEdit', 'Log']);

        $this->assertEquals(['ValueSuggest', 'BulkEdit', 'Log', 'Common'], $sorted);
    }

    public function testUnknownModulesKeepTheirRelativeOrder(): void
    {
        $modules = ['ValueSuggest', 'BulkEdit', 'EasyAdmin'];

        // sorting is stable, so a list without known dependencies is left as it is
        $this->assertEquals($modules, ModuleDependencyOrder::sort($modules));
        $this->assertEquals($modules, ModuleDependencyOrder::reverseSort($modules));
    }

    public function testReverseSortIsTheMirrorOfSort(): void
    {
        $modules = ['IiifServer', 'ValueSuggest', 'Common', 'BulkEdit', 'Log'];

        $this->assertEquals(
            ModuleDependencyOrder::sort($modules),
            array_reverse(ModuleDependencyOrder::reverseSort(array_reverse($modules)))
        );
    }

    public function testEmptyList(): void
    {
        $this->assertEquals([], ModuleDependencyOrder::sort([]));
        $this->assertEquals([], ModuleDependencyOrder::reverseSort([]));
    }

    public function testSingleModule(): void
    {
        $this->assertEquals(['Common'], ModuleDependencyOrder::sort(['Common']));
        $this->assertEquals(['BulkEdit'], ModuleDependencyOrder::reverseSort(['BulkEdit']));
    }
}
