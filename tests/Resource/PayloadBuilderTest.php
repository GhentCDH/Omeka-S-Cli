<?php

namespace Tests\Resource;

use OSC\Resource\BuildResult;
use OSC\Resource\Identity;
use OSC\Resource\PayloadBuilder;
use OSC\Resource\PortableJsonLd;
use OSC\Resource\ResourceImportException;
use OSC\Resource\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadBuilder::class)]
#[CoversClass(BuildResult::class)]
#[CoversClass(Identity::class)]
#[CoversClass(ResourceImportException::class)]
#[UsesClass(PortableJsonLd::class)]
class PayloadBuilderTest extends TestCase
{
    public static function monaLisa(): array
    {
        return [
            '@context' => [
                'dcterms' => 'http://purl.org/dc/terms/',
                'dctype' => 'http://purl.org/dc/dcmitype/',
                'o' => PortableJsonLd::O_NS,
                'o-ref' => PortableJsonLd::NS,
            ],
            '@type' => ['o:Item', 'dctype:StillImage'],
            'o-ref:id' => ['dcterms:identifier' => 'obj-7'],
            'o:title' => 'Mona Lisa',
            'o:is_public' => true,
            'o:resource_class' => ['o:term' => 'dctype:StillImage'],
            'o:resource_template' => ['o:label' => 'Artwork'],
            'o:item_set' => [['o-ref:ref' => ['dcterms:title' => 'Paintings']]],
            'o:site' => [['o:slug' => 'collections']],
            'dcterms:title' => [['type' => 'literal', 'is_public' => true, '@value' => 'Mona Lisa']],
            'dcterms:relation' => [['type' => 'resource:item', 'is_public' => true, 'o-ref:ref' => ['dcterms:identifier' => 'obj-42']]],
        ];
    }

    private function builder(?FakeCatalog $catalog = null): PayloadBuilder
    {
        return new PayloadBuilder($catalog ?? FakeCatalog::sample());
    }

    /** @return string[] */
    private function problems(array $document, ResourceType $type = ResourceType::Item, ?string $identifyBy = null): array
    {
        try {
            $this->builder()->build($document, $type, 'x', $identifyBy);
        } catch (ResourceImportException $e) {
            return $e->problems();
        }
        $this->fail('expected ResourceImportException');
    }

    public function testPortableDocumentBecomesAPayload(): void
    {
        $result = $this->builder()->build(self::monaLisa(), ResourceType::Item, 'mona.jsonld');
        $p = $result->payload();
        $this->assertSame([['type' => 'literal', 'is_public' => true, '@value' => 'Mona Lisa', 'property_id' => 'auto']], $p['dcterms:title']);
        $this->assertSame(['o:id' => 5], $p['o:resource_class']);
        $this->assertSame(['o:id' => 3], $p['o:resource_template']);
        $this->assertSame([['o:id' => 9]], $p['o:item_set']);
        $this->assertSame([['o:id' => 1]], $p['o:site']);
        $this->assertSame(42, $p['dcterms:relation'][0]['value_resource_id']);
        $this->assertArrayNotHasKey('o-ref:ref', $p['dcterms:relation'][0]);
        $this->assertSame(true, $p['o:is_public']);
        foreach (['@context', '@type', 'o-ref:id', 'o:title'] as $key) {
            $this->assertArrayNotHasKey($key, $p);
        }
        $this->assertEquals(new Identity('dcterms:identifier', 'obj-7'), $result->identity());
        $this->assertSame('Mona Lisa', $result->title());
        $this->assertSame([], $result->warnings());
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $doc = [
            '@context' => ['foo' => 'https://example.org/ns/foo#'],
            'foo:bar' => [['type' => 'literal', '@value' => 'x']],
            'dcterms:nope' => [['type' => 'literal', '@value' => 'x']],
            'dcterms:title' => [['@value' => 'no type'], ['type' => 'numeric:timestamp', '@value' => '2020']],
        ];
        try {
            $this->builder()->build($doc, ResourceType::Item, 'bad.jsonld');
            $this->fail('expected ResourceImportException');
        } catch (ResourceImportException $e) {
            $this->assertSame([
                'property "foo:bar" uses vocabulary https://example.org/ns/foo#, which is not installed (vocabulary:import)',
                'property "dcterms:nope" does not exist in vocabulary http://purl.org/dc/terms/',
                'property "dcterms:title": a value has no "type"',
                'property "dcterms:title": data type "numeric:timestamp" is not available (install the NumericDataTypes module)',
            ], $e->problems());
            $this->assertStringStartsWith('bad.jsonld: property "foo:bar"', $e->getMessage());
            $this->assertSame(4, substr_count($e->getMessage(), 'bad.jsonld: '));
        }
    }

    public function testUnknownPrefixWithoutContext(): void
    {
        $this->assertSame(
            ['property "foo:bar": unknown prefix "foo" (not in the file\'s @context, and no vocabulary with this prefix is installed)'],
            $this->problems(['foo:bar' => [['type' => 'literal', '@value' => 'x']]])
        );
    }

    public function testUnknownDataTypeWithoutHint(): void
    {
        $this->assertSame(
            ['property "dcterms:title": data type "unknown:thing" is not available'],
            $this->problems(['dcterms:title' => [['type' => 'unknown:thing', '@value' => 'x']]])
        );
    }

    public function testUnknownClassAndTemplate(): void
    {
        $this->assertSame(
            ['resource class "dctype:Foo" not found', 'resource template "Nope" not found (resource-template:import)'],
            $this->problems(['o:resource_class' => ['o:term' => 'dctype:Foo'], 'o:resource_template' => ['o:label' => 'Nope']])
        );
    }

    public function testPrefixIsRemappedByNamespace(): void
    {
        $doc = [
            '@context' => ['sdo' => 'https://schema.org/'],
            'sdo:name' => [['type' => 'literal', '@value' => 'X']],
        ];
        $payload = $this->builder()->build($doc, ResourceType::Item, 'x')->payload();
        $this->assertArrayHasKey('schema:name', $payload);
        $this->assertArrayNotHasKey('sdo:name', $payload);
    }

    public function testUnresolvedReferenceWarnsAndDropsTheValue(): void
    {
        $doc = ['dcterms:relation' => [['type' => 'resource:item', 'o-ref:ref' => ['dcterms:identifier' => 'missing']]]];
        $result = $this->builder()->build($doc, ResourceType::Item, 'x');
        $this->assertArrayNotHasKey('dcterms:relation', $result->payload());
        $this->assertContains('x: link "dcterms:relation" to items with dcterms:identifier = "missing" not found; the value is skipped', $result->warnings());
    }

    public function testAmbiguousReferenceWarnsAndDropsTheValue(): void
    {
        $catalog = FakeCatalog::sample();
        $catalog->values['items'][43] = ['dcterms:identifier' => 'obj-42'];
        $doc = ['dcterms:relation' => [['type' => 'resource:item', 'o-ref:ref' => ['dcterms:identifier' => 'obj-42']]]];
        $result = $this->builder($catalog)->build($doc, ResourceType::Item, 'x');
        $this->assertArrayNotHasKey('dcterms:relation', $result->payload());
        $this->assertContains('x: link "dcterms:relation" to items with dcterms:identifier = "obj-42" matches 2 items; the value is skipped', $result->warnings());
    }

    public function testRefKeyIsFoundByNamespace(): void
    {
        $doc = [
            '@context' => ['r' => PortableJsonLd::NS],
            'r:id' => ['dcterms:identifier' => 'obj-7'],
            'o:item_set' => [['r:ref' => ['dcterms:title' => 'Paintings']]],
        ];
        $result = $this->builder()->build($doc, ResourceType::Item, 'x');
        $this->assertSame([['o:id' => 9]], $result->payload()['o:item_set']);
        $this->assertArrayNotHasKey('r:id', $result->payload());
        $this->assertEquals(new Identity('dcterms:identifier', 'obj-7'), $result->identity());
    }

    public function testRawOmekaJsonLdIsAccepted(): void
    {
        $doc = [
            '@context' => 'http://localhost/api-context',
            '@id' => 'http://localhost/api/items/1',
            'o:id' => 1,
            'o:owner' => ['@id' => 'http://localhost/api/users/1', 'o:id' => 1],
            'o:resource_class' => ['@id' => 'http://localhost/api/resource_classes/5', 'o:id' => 5],
            'o:media' => [['@id' => 'http://localhost/api/media/3', 'o:id' => 3]],
            'o:item_set' => [['@id' => 'http://localhost/api/item_sets/9', 'o:id' => 9]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, 'property_label' => 'Title', 'is_public' => true, '@value' => 'qsdf']],
            'dcterms:relation' => [[
                'type' => 'resource:item', 'property_id' => 13, 'property_label' => 'Relation', 'is_public' => true,
                '@id' => 'http://localhost/api/items/42', 'value_resource_id' => 42, 'value_resource_name' => 'items',
                'url' => null, 'display_title' => 'Lady with an Ermine', 'thumbnail_url' => null,
            ]],
        ];
        $p = $this->builder()->build($doc, ResourceType::Item, 'raw')->payload();
        $this->assertSame([['type' => 'literal', 'is_public' => true, '@value' => 'qsdf', 'property_id' => 'auto']], $p['dcterms:title']);
        $this->assertSame([['type' => 'resource:item', 'is_public' => true, 'value_resource_id' => 42, 'property_id' => 'auto']], $p['dcterms:relation']);
        $this->assertSame(['o:id' => 5], $p['o:resource_class']);
        $this->assertSame([['o:id' => 9]], $p['o:item_set']);
        foreach (['o:owner', 'o:media', 'o:id', '@id'] as $key) {
            $this->assertArrayNotHasKey($key, $p);
        }
    }

    public function testCustomVocabLabelBecomesId(): void
    {
        $doc = ['dcterms:subject' => [['type' => 'customvocab:Colours', '@value' => 'Red']]];
        $this->assertSame('customvocab:7', $this->builder()->build($doc, ResourceType::Item, 'x')->payload()['dcterms:subject'][0]['type']);
        $this->assertSame(
            ['property "dcterms:subject": custom vocabulary "Nope" not found'],
            $this->problems(['dcterms:subject' => [['type' => 'customvocab:Nope', '@value' => 'Red']]])
        );
    }

    public function testAnnotationsAreProcessedLikeValues(): void
    {
        $doc = ['dcterms:title' => [[
            'type' => 'literal', '@value' => 'X',
            '@annotation' => ['dcterms:description' => [['type' => 'literal', 'property_id' => 4, 'property_label' => 'Description', '@value' => 'note']]],
        ]]];
        $value = $this->builder()->build($doc, ResourceType::Item, 'x')->payload()['dcterms:title'][0];
        $this->assertSame([['type' => 'literal', '@value' => 'note', 'property_id' => 'auto']], $value['@annotation']['dcterms:description']);

        $doc['dcterms:title'][0]['@annotation'] = ['dcterms:nope' => [['type' => 'literal', '@value' => 'note']]];
        $this->assertSame(
            ['annotation of "dcterms:title": property "dcterms:nope" does not exist in vocabulary http://purl.org/dc/terms/'],
            $this->problems($doc)
        );
    }

    public function testUnresolvedSitesFallBackToTheDefaultSite(): void
    {
        $catalog = FakeCatalog::sample();
        $catalog->defaultSite = 1;
        $result = $this->builder($catalog)->build(['o:site' => [['o:slug' => 'nope']]], ResourceType::Item, 'x');
        $this->assertSame([['o:id' => 1]], $result->payload()['o:site']);
        $this->assertContains('x: site "nope" not found; the item is not added to it', $result->warnings());

        $result = $this->builder()->build(['o:site' => [['o:slug' => 'nope']]], ResourceType::Item, 'x');
        $this->assertArrayNotHasKey('o:site', $result->payload());
    }

    public function testSitesAreMatchedByTitleToo(): void
    {
        $result = $this->builder()->build(['o:site' => [['o:slug' => 'Collections']]], ResourceType::Item, 'x');
        $this->assertSame([['o:id' => 1]], $result->payload()['o:site']);
    }

    public function testIdentityFallsBackToTitle(): void
    {
        $doc = ['dcterms:title' => [['type' => 'literal', '@value' => 'Mona Lisa']]];
        $this->assertEquals(new Identity(null, 'Mona Lisa'), $this->builder()->build($doc, ResourceType::Item, 'x')->identity());

        $doc['o:title'] = 'Display title';
        $this->assertEquals(new Identity(null, 'Display title'), $this->builder()->build($doc, ResourceType::Item, 'x')->identity());

        $this->assertNull($this->builder()->build(['o:is_public' => true], ResourceType::Item, 'x')->identity());
    }

    public function testIdentifyByOverridesAndRequiresAValue(): void
    {
        $doc = self::monaLisa();
        $doc['dcterms:identifier'] = [['type' => 'literal', '@value' => 'inv-7']];
        $this->assertEquals(
            new Identity('dcterms:identifier', 'inv-7'),
            $this->builder()->build($doc, ResourceType::Item, 'x', 'dcterms:identifier')->identity()
        );
        $this->assertSame(
            ['--identify-by dcterms:description: the resource has no value for it'],
            $this->problems(self::monaLisa(), ResourceType::Item, 'dcterms:description')
        );
    }

    public function testItemSetsDropItemOnlyKeys(): void
    {
        $p = $this->builder()->build(
            ['o:is_open' => true, 'o:site' => [['o:slug' => 'collections']], 'o:item_set' => [['o:id' => 9]]],
            ResourceType::ItemSet,
            'x'
        )->payload();
        $this->assertSame(['o:is_open' => true], $p);
    }

    public function testModuleDataIsDroppedWithAWarning(): void
    {
        $doc = self::monaLisa();
        $doc['o-module-mapping:marker'] = [['@id' => 'http://localhost/api/mapping_markers/1', 'o:id' => 1]];
        $result = $this->builder()->build($doc, ResourceType::Item, 'x');
        $this->assertArrayNotHasKey('o-module-mapping:marker', $result->payload());
        $this->assertSame(['x: "o-module-mapping:marker" is module data and is not imported'], $result->warnings());
    }

    public function testResourceWithoutIdentityWarns(): void
    {
        $result = $this->builder()->build(['dcterms:description' => [['type' => 'literal', '@value' => 'No title']]], ResourceType::Item, 'x');
        $this->assertNull($result->identity());
        $this->assertSame(
            ['x: the item has no o-ref:id and no title, so it cannot be matched: it is created on every import'],
            $result->warnings()
        );
    }
}
