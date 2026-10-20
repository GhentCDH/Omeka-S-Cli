<?php
namespace Tests\Resource;

use OSC\Helper\MediaConfig;
use OSC\Resource\PortableJsonLd;
use OSC\Resource\SimpleContentMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimpleContentMapper::class)]
#[UsesClass(MediaConfig::class)]
#[UsesClass(PortableJsonLd::class)]
class SimpleContentMapperTest extends TestCase
{
    public function testItemMapsToDublinCoreAndReferences(): void
    {
        [$doc, $media] = SimpleContentMapper::item([
            'title' => 'Mona Lisa', 'description' => 'Portrait', 'creator' => 'Leonardo',
            'itemSets' => ['Paintings'], 'sites' => ['collections'],
            'media' => [
                ['type' => 'url', 'url' => 'https://example.org/a.jpg', 'title' => 'Front', 'altText' => 'A portrait'],
                ['type' => 'url', 'url' => '/abs/blueprint/media/b.png'],
            ],
        ]);

        $this->assertSame('o:Item', $doc['@type']);
        $this->assertSame(
            ['dcterms' => 'http://purl.org/dc/terms/', 'o' => PortableJsonLd::O_NS, 'o-ref' => PortableJsonLd::NS],
            $doc['@context']
        );
        $this->assertSame([['type' => 'literal', '@value' => 'Mona Lisa']], $doc['dcterms:title']);
        $this->assertSame([['type' => 'literal', '@value' => 'Portrait']], $doc['dcterms:description']);
        $this->assertSame([['type' => 'literal', '@value' => 'Leonardo']], $doc['dcterms:creator']);
        $this->assertSame([['o-ref:ref' => ['dcterms:title' => 'Paintings']]], $doc['o:item_set']);
        // sites travel through the import config (ItemImportConfig::sites()), where no sites means the default site
        $this->assertArrayNotHasKey('o:site', $doc);
        $this->assertArrayNotHasKey('o-ref:id', $doc);

        $this->assertSame('url', $media[0]->ingester());
        $this->assertSame(
            ['dcterms:title' => [['type' => 'literal', 'property_id' => 'auto', '@value' => 'Front']], 'o:alt_text' => 'A portrait'],
            array_intersect_key($media[0]->toApiPayload(), ['dcterms:title' => 1, 'o:alt_text' => 1])
        );
        $this->assertSame('upload', $media[1]->ingester());
        $this->assertSame('/abs/blueprint/media/b.png', $media[1]->source());
    }

    public function testOptionalFieldsAreOmitted(): void
    {
        [$doc, $media] = SimpleContentMapper::item(['title' => 'Only a title']);

        $this->assertSame(['@context', '@type', 'dcterms:title'], array_keys($doc));
        $this->assertSame([], $media);
    }

    public function testItemSetMapsTitleAndDescription(): void
    {
        $doc = SimpleContentMapper::itemSet(['title' => 'Paintings', 'description' => 'Oil on wood']);

        $this->assertSame('o:ItemSet', $doc['@type']);
        $this->assertSame([['type' => 'literal', '@value' => 'Paintings']], $doc['dcterms:title']);
        $this->assertSame([['type' => 'literal', '@value' => 'Oil on wood']], $doc['dcterms:description']);
        $this->assertArrayNotHasKey('o:item_set', $doc);
    }
}
