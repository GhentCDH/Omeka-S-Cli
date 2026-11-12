<?php

namespace Tests\Resource;

use OSC\Resource\PortableJsonLd;
use OSC\Resource\RefResolution;
use OSC\Resource\RefResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefResolver::class)]
#[CoversClass(RefResolution::class)]
class RefResolverTest extends TestCase
{
    public function testResolvesAReferenceToItsId(): void
    {
        $resolver = RefResolver::forDocument(FakeCatalog::sample(), []);
        $resolution = $resolver->resolve(['o-ref:ref' => ['dcterms:identifier' => 'set-1'], 'o:title' => 'Paintings'], 'item_sets');

        $this->assertTrue($resolution->isResolved());
        $this->assertSame(9, $resolution->id);
        $this->assertSame(RefResolution::OK, $resolution->status);
    }

    public function testFindsTheRefKeyByNamespaceUnderAnyPrefix(): void
    {
        $resolver = RefResolver::forDocument(FakeCatalog::sample(), ['@context' => ['r' => PortableJsonLd::NS]]);

        $this->assertSame('r:ref', $resolver->refKey());
        $this->assertTrue($resolver->isRef(['r:ref' => ['dcterms:identifier' => 'set-1']]));
        $this->assertFalse($resolver->isRef(['o-ref:ref' => ['dcterms:identifier' => 'set-1']]));
        $this->assertSame(9, $resolver->resolve(['r:ref' => ['dcterms:identifier' => 'set-1']], 'item_sets')->id);
    }

    public function testWithoutContextTheDefaultPrefixIsUsed(): void
    {
        $resolver = RefResolver::forDocument(FakeCatalog::sample(), []);

        $this->assertSame('o-ref:ref', $resolver->refKey());
        $this->assertFalse($resolver->isRef(['o:id' => 9]));
        $this->assertFalse($resolver->isRef('set-1'));
    }

    public function testMapsTheTermToTheTargetPrefixByNamespace(): void
    {
        $document = ['@context' => ['dc' => 'http://purl.org/dc/terms/']];
        $resolver = RefResolver::forDocument(FakeCatalog::sample(), $document);

        $this->assertSame(['dcterms:identifier', '', 'http://purl.org/dc/terms/'], $resolver->mapTerm('dc:identifier'));
        $this->assertSame(9, $resolver->resolve(['o-ref:ref' => ['dc:identifier' => 'set-1']], 'item_sets')->id);
    }

    public function testNoMatch(): void
    {
        $resolution = RefResolver::forDocument(FakeCatalog::sample(), [])
            ->resolve(['o-ref:ref' => ['dcterms:identifier' => 'missing']], 'item_sets');

        $this->assertFalse($resolution->isResolved());
        $this->assertSame(RefResolution::NOT_FOUND, $resolution->status);
        $this->assertSame('dcterms:identifier', $resolution->term);
        $this->assertSame('missing', $resolution->value);
        $this->assertSame('link "o:item_set" to item sets with dcterms:identifier = "missing" not found', $resolution->describe('link "o:item_set"'));
    }

    public function testSeveralMatches(): void
    {
        $catalog = FakeCatalog::sample();
        $catalog->values['item_sets'][10] = ['dcterms:identifier' => 'set-1'];
        $resolution = RefResolver::forDocument($catalog, [])->resolve(['o-ref:ref' => ['dcterms:identifier' => 'set-1']], 'item_sets');

        $this->assertSame(RefResolution::AMBIGUOUS, $resolution->status);
        $this->assertSame(2, $resolution->matches);
        $this->assertNull($resolution->id);
        $this->assertSame('link "o:item_set" to item sets with dcterms:identifier = "set-1" matches 2 item sets', $resolution->describe('link "o:item_set"'));
    }

    /** @return array<string,array{0:mixed}> */
    public static function invalidReferences(): array
    {
        return [
            'two properties' => [['o-ref:ref' => ['dcterms:identifier' => 'set-1', 'dcterms:title' => 'Paintings']]],
            'empty value' => [['o-ref:ref' => ['dcterms:identifier' => '']]],
            'list value' => [['o-ref:ref' => ['dcterms:identifier' => ['set-1']]]],
            'not an object' => [['o-ref:ref' => 'set-1']],
            'no ref key' => [['property' => 'dcterms:identifier', 'value' => 'set-1']],
            'not an array' => ['set-1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReferences')]
    public function testInvalidReferences(mixed $node): void
    {
        $resolution = RefResolver::forDocument(FakeCatalog::sample(), [])->resolve($node, 'item_sets');

        $this->assertSame(RefResolution::INVALID, $resolution->status);
        $this->assertSame('link "o:item_set" has an invalid reference', $resolution->describe('link "o:item_set"'));
    }
}
