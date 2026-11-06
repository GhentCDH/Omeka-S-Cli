<?php

namespace Tests\Resource;

use OSC\Resource\PortableJsonLd;
use OSC\Resource\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PortableJsonLd::class)]
#[CoversClass(ResourceType::class)]
class PortableJsonLdTest extends TestCase
{
    public function testContextMapKeepsStringEntries(): void
    {
        $this->assertSame(
            ['dcterms' => 'http://purl.org/dc/terms/'],
            PortableJsonLd::contextMap(['dcterms' => 'http://purl.org/dc/terms/', 'x' => ['@id' => 'y']])
        );
        $this->assertSame([], PortableJsonLd::contextMap('http://localhost/api-context'));
    }

    public function testKeyForFindsTheKeyByNamespace(): void
    {
        $this->assertSame('x:ref', PortableJsonLd::keyFor(['x' => PortableJsonLd::NS], 'ref'));
        $this->assertSame('o-ref:id', PortableJsonLd::keyFor([], 'id'));
    }

    public function testValueListDetection(): void
    {
        $this->assertTrue(PortableJsonLd::isValueList([['type' => 'literal', '@value' => 'a']]));
        $this->assertTrue(PortableJsonLd::isValueList([['o-ref:ref' => ['dcterms:title' => 'b']]]));
        $this->assertFalse(PortableJsonLd::isValueList(['o:term' => 'dctype:Text']));
        $this->assertFalse(PortableJsonLd::isValueList([]));
        $this->assertFalse(PortableJsonLd::isValueList('text'));
    }

    public function testSplitTerm(): void
    {
        $this->assertSame(['dcterms', 'title'], PortableJsonLd::splitTerm('dcterms:title'));
        $this->assertNull(PortableJsonLd::splitTerm('@type'));
        $this->assertNull(PortableJsonLd::splitTerm('o:title'));
        $this->assertNull(PortableJsonLd::splitTerm('title'));
    }

    public function testResourceTypes(): void
    {
        $this->assertSame('o:ItemSet', ResourceType::ItemSet->jsonLdType());
        $this->assertSame('item-set', ResourceType::ItemSet->commandPrefix());
        $this->assertSame('item', ResourceType::Item->label());
        $this->assertSame(ResourceType::Item, ResourceType::fromApiName('items'));
        $this->assertNull(ResourceType::fromApiName('media'));
    }
}
