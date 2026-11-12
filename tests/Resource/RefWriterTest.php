<?php

namespace Tests\Resource;

use OSC\Resource\PortableJsonLd;
use OSC\Resource\RefWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefWriter::class)]
class RefWriterTest extends TestCase
{
    public function testWritesTheRecordsValueForTheTerm(): void
    {
        $writer = new RefWriter(FakeCatalog::sample(), 'dcterms:identifier');

        $this->assertSame(['o-ref:ref' => ['dcterms:identifier' => 'set-1']], $writer->write('item_sets', 9));
        $this->assertSame(['o-ref:ref' => ['dcterms:identifier' => 'obj-42']], $writer->write('items', 42));
    }

    public function testWritesAUriValue(): void
    {
        $catalog = FakeCatalog::sample();
        $catalog->values['item_sets'][9]['dcterms:identifier'] = 'urn:example:item-set:photographs';

        $this->assertSame(
            ['o-ref:ref' => ['dcterms:identifier' => 'urn:example:item-set:photographs']],
            (new RefWriter($catalog, 'dcterms:identifier'))->write('item_sets', 9)
        );
    }

    public function testNullWhenTheRecordHasNoValue(): void
    {
        $writer = new RefWriter(FakeCatalog::sample(), 'dcterms:subject');

        $this->assertNull($writer->write('item_sets', 9));
        $this->assertNull($writer->write('item_sets', 404));
    }

    public function testContextDeclaresTheNamespacesTheRefsNeed(): void
    {
        $writer = new RefWriter(FakeCatalog::sample(), 'schema:name');

        $this->assertSame([
            'o' => PortableJsonLd::O_NS,
            'o-ref' => PortableJsonLd::NS,
            'schema' => 'https://schema.org/',
        ], $writer->context());
    }
}
