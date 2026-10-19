<?php

namespace Tests\Resource;

use InvalidArgumentException;
use OSC\Resource\QueryBuilder;
use OSC\Resource\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryBuilder::class)]
class QueryBuilderTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        return new QueryBuilder(PayloadBuilderTest::catalog());
    }

    public function testFiltersBecomeAnOmekaQuery(): void
    {
        $q = $this->builder()->build(ResourceType::Item, [
            'class' => 'dctype:StillImage', 'template' => 'Artwork', 'site' => 'collections', 'itemSet' => '9',
            'property' => ['dcterms:creator=Leonardo'], 'public' => true, 'limit' => '10', 'page' => '2', 'text' => 'mona',
        ]);
        $this->assertSame(5, $q['resource_class_id']);
        $this->assertSame(3, $q['resource_template_id']);
        $this->assertSame(1, $q['site_id']);
        $this->assertSame(9, $q['item_set_id']);
        $this->assertSame([['joiner' => 'and', 'property' => 'dcterms:creator', 'type' => 'eq', 'text' => 'Leonardo']], $q['property']);
        $this->assertSame(1, $q['is_public']);
        $this->assertSame(10, $q['per_page']);
        $this->assertSame(2, $q['page']);
        $this->assertSame('mona', $q['fulltext_search']);
    }

    public function testEmptyFiltersGiveAnEmptyQuery(): void
    {
        $this->assertSame([], $this->builder()->build(ResourceType::Item, ['class' => null, 'property' => [], 'public' => false]));
    }

    public function testLimitDefaultsToPageOne(): void
    {
        $q = $this->builder()->build(ResourceType::Item, ['limit' => '5']);
        $this->assertSame(['per_page' => 5, 'page' => 1], $q);
    }

    public function testRawQueryWins(): void
    {
        $q = $this->builder()->build(ResourceType::Item, ['public' => true, 'query' => 'sort_by=title&is_public=0']);
        $this->assertSame('0', $q['is_public']);
        $this->assertSame('title', $q['sort_by']);
    }

    public function testItemSetFilterIsForItemsOnly(): void
    {
        $this->assertArrayNotHasKey('item_set_id', $this->builder()->build(ResourceType::ItemSet, ['itemSet' => '9']));
    }

    public function testUnknownClassIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown resource class "dctype:Foo".');
        $this->builder()->build(ResourceType::Item, ['class' => 'dctype:Foo']);
    }

    public function testUnknownTemplateAndSiteAreErrors(): void
    {
        foreach ([['template' => 'Nope'], ['site' => 'nope']] as $filters) {
            try {
                $this->builder()->build(ResourceType::Item, $filters);
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/^Unknown (resource template "Nope"|site "nope")\.$/', $e->getMessage());
            }
        }
    }

    public function testPropertyFilterNeedsAnEqualsSign(): void
    {
        $this->expectExceptionMessage('--property expects term=value, got "dcterms:creator".');
        $this->builder()->build(ResourceType::Item, ['property' => ['dcterms:creator']]);
    }

    public function testPublicAndPrivateExcludeEachOther(): void
    {
        $this->expectExceptionMessage('Use either --public or --private.');
        $this->builder()->build(ResourceType::Item, ['public' => true, 'private' => true]);
    }
}
