<?php

namespace Tests\Resource;

use OSC\Resource\BuildResult;
use OSC\Resource\Exporter;
use OSC\Resource\ExportResult;
use OSC\Resource\Identity;
use OSC\Resource\PayloadBuilder;
use OSC\Resource\PortableJsonLd;
use OSC\Resource\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Exporter::class)]
#[CoversClass(ExportResult::class)]
#[UsesClass(PortableJsonLd::class)]
#[UsesClass(PayloadBuilder::class)]
#[UsesClass(BuildResult::class)]
#[UsesClass(Identity::class)]
class ExporterTest extends TestCase
{
    /** Mona Lisa (item 7) as the Omeka API returns it. */
    public static function omekaJsonLd(): array
    {
        $api = 'http://localhost/api';
        return [
            '@context' => 'http://localhost/api-context',
            '@id' => "{$api}/items/7",
            '@type' => ['o:Item', 'dctype:StillImage'],
            'o:id' => 7,
            'o:is_public' => true,
            'o:owner' => ['@id' => "{$api}/users/1", 'o:id' => 1],
            'o:resource_class' => ['@id' => "{$api}/resource_classes/5", 'o:id' => 5],
            'o:resource_template' => ['@id' => "{$api}/resource_templates/3", 'o:id' => 3],
            'o:thumbnail' => null,
            'o:title' => 'Mona Lisa',
            'thumbnail_display_urls' => ['large' => null, 'medium' => null, 'square' => null],
            'o:created' => ['@value' => '2026-10-17T19:57:23+00:00', '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime'],
            'o:modified' => ['@value' => '2026-10-17T19:57:23+00:00', '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime'],
            'o:primary_media' => null,
            'o:media' => [['@id' => "{$api}/media/3", 'o:id' => 3]],
            'o:item_set' => [['@id' => "{$api}/item_sets/9", 'o:id' => 9]],
            'o:site' => [['@id' => "{$api}/sites/1", 'o:id' => 1]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, 'property_label' => 'Title', 'is_public' => true, '@value' => 'Mona Lisa']],
            'dcterms:identifier' => [['type' => 'literal', 'property_id' => 10, 'property_label' => 'Identifier', 'is_public' => true, '@value' => 'obj-7']],
            'dcterms:relation' => [[
                'type' => 'resource:item', 'property_id' => 13, 'property_label' => 'Relation', 'is_public' => true,
                '@id' => "{$api}/items/42", 'value_resource_id' => 42, 'value_resource_name' => 'items', 'url' => null,
                'display_title' => 'Lady with an Ermine', 'thumbnail_url' => null, 'thumbnail_title' => null, 'thumbnail_type' => null,
            ]],
            'o-module-mapping:features' => [['o:id' => 1]],
        ];
    }

    private function exporter(string $referenceBy = 'dcterms:title', ?FakeCatalog $catalog = null): Exporter
    {
        return new Exporter($catalog ?? PayloadBuilderTest::catalog(), $referenceBy);
    }

    public function testExportDropsInstanceIdsAndInlinesTheContext(): void
    {
        $result = $this->exporter('dcterms:identifier')->export(self::omekaJsonLd(), ResourceType::Item);
        $doc = $result->document();
        $this->assertSame([
            '@context', '@type', 'o-ref:id', 'o:title', 'o:is_public', 'o:resource_class', 'o:resource_template',
            'o:item_set', 'o:site', 'dcterms:title', 'dcterms:identifier', 'dcterms:relation',
        ], array_keys($doc));
        $this->assertSame([
            'dcterms' => 'http://purl.org/dc/terms/',
            'dctype' => 'http://purl.org/dc/dcmitype/',
            'o' => PortableJsonLd::O_NS,
            'o-ref' => PortableJsonLd::NS,
        ], $doc['@context']);
        $this->assertSame(['dcterms:identifier' => 'obj-7'], $doc['o-ref:id']);
        $this->assertSame(['o:term' => 'dctype:StillImage'], $doc['o:resource_class']);
        $this->assertSame(['o:label' => 'Artwork'], $doc['o:resource_template']);
        $this->assertSame([['o-ref:ref' => ['dcterms:identifier' => 'set-1']]], $doc['o:item_set']);
        $this->assertSame([['o:slug' => 'collections']], $doc['o:site']);
        $this->assertSame([['type' => 'literal', 'is_public' => true, '@value' => 'Mona Lisa']], $doc['dcterms:title']);
        $this->assertSame(
            [['type' => 'resource:item', 'is_public' => true, 'o-ref:ref' => ['dcterms:identifier' => 'obj-42']]],
            $doc['dcterms:relation']
        );
        $this->assertSame([], $result->warnings());
    }

    public function testMissingIdentityValueWarnsAndOmitsId(): void
    {
        $result = $this->exporter('schema:name')->export(self::omekaJsonLd(), ResourceType::Item);
        $this->assertArrayNotHasKey('o-ref:id', $result->document());
        $this->assertContains(
            '"Mona Lisa" (item 7) has no schema:name value; it will be matched by title on import',
            $result->warnings()
        );
    }

    public function testLinkWithoutReferenceValueIsOmittedWithWarning(): void
    {
        $catalog = PayloadBuilderTest::catalog();
        unset($catalog->values['items'][42]['dcterms:title']);
        $result = $this->exporter('dcterms:title', $catalog)->export(self::omekaJsonLd(), ResourceType::Item);
        $this->assertArrayNotHasKey('dcterms:relation', $result->document());
        $this->assertSame(
            ['"Mona Lisa" (item 7): the link "dcterms:relation" to item 42 has no dcterms:title value and is not exported'],
            $result->warnings()
        );
    }

    public function testGenericResourceValuesKeepTheirResourceName(): void
    {
        $jsonLd = self::omekaJsonLd();
        $jsonLd['dcterms:relation'][0]['type'] = 'resource';
        $doc = $this->exporter('dcterms:identifier')->export($jsonLd, ResourceType::Item)->document();
        $this->assertSame('items', $doc['dcterms:relation'][0]['value_resource_name']);
    }

    public function testCustomVocabIdBecomesLabel(): void
    {
        $jsonLd = self::omekaJsonLd();
        $jsonLd['dcterms:subject'] = [['type' => 'customvocab:7', 'property_id' => 3, 'is_public' => true, '@value' => 'Red']];
        $doc = $this->exporter()->export($jsonLd, ResourceType::Item)->document();
        $this->assertSame('customvocab:Colours', $doc['dcterms:subject'][0]['type']);
    }

    public function testItemSetExportKeepsIsOpenAndDropsItemKeys(): void
    {
        $doc = $this->exporter()->export([
            '@type' => 'o:ItemSet', 'o:id' => 9, 'o:is_public' => true, 'o:is_open' => false, 'o:title' => 'Paintings',
            'o:items' => ['@id' => 'http://localhost/api/items?item_set_id=9'],
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, 'is_public' => true, '@value' => 'Paintings']],
        ], ResourceType::ItemSet)->document();
        $this->assertSame(false, $doc['o:is_open']);
        $this->assertSame(['dcterms:title' => 'Paintings'], $doc['o-ref:id']);
        $this->assertArrayNotHasKey('o:items', $doc);
    }

    public function testExportedDocumentRoundTripsThroughThePayloadBuilder(): void
    {
        $catalog = PayloadBuilderTest::catalog();
        $doc = $this->exporter('dcterms:identifier', $catalog)->export(self::omekaJsonLd(), ResourceType::Item)->document();
        $result = (new PayloadBuilder($catalog))->build($doc, ResourceType::Item, 'x');
        $payload = $result->payload();
        $this->assertSame(42, $payload['dcterms:relation'][0]['value_resource_id']);
        $this->assertSame([['o:id' => 9]], $payload['o:item_set']);
        $this->assertSame(['o:id' => 5], $payload['o:resource_class']);
        $this->assertSame(['o:id' => 3], $payload['o:resource_template']);
        $this->assertSame([['o:id' => 1]], $payload['o:site']);
        $this->assertEquals(new Identity('dcterms:identifier', 'obj-7'), $result->identity());
    }
}
