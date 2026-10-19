<?php

namespace Tests\Resource;

use InvalidArgumentException;
use LogicException;
use OSC\Helper\MediaConfig;
use OSC\Resource\BuildResult;
use OSC\Resource\Identity;
use OSC\Resource\ImportResult;
use OSC\Resource\PayloadBuilder;
use OSC\Resource\PortableJsonLd;
use OSC\Resource\ResourceImportException;
use OSC\Resource\ResourceImporter;
use OSC\Resource\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceImporter::class)]
#[CoversClass(ImportResult::class)]
#[UsesClass(PayloadBuilder::class)]
#[UsesClass(BuildResult::class)]
#[UsesClass(Identity::class)]
#[UsesClass(PortableJsonLd::class)]
#[UsesClass(MediaConfig::class)]
class ResourceImporterTest extends TestCase
{
    private function importer(?FakeCatalog $catalog = null, ?\Closure $fetchJson = null): ResourceImporter
    {
        return new ResourceImporter(null, $catalog ?? FakeCatalog::sample(), $fetchJson);
    }

    public function testSitesReplaceTheDocumentSites(): void
    {
        $document = ['o:site' => [['o:slug' => 'other']], 'dcterms:title' => [['type' => 'literal', '@value' => 'X']]];

        $this->assertSame(
            [['o:slug' => 'collections'], ['o:slug' => 'Archive']],
            $this->importer()->withSites($document, ['collections', 'Archive'])['o:site']
        );
    }

    public function testNoSitesMeansTheDefaultSite(): void
    {
        $catalog = FakeCatalog::sample();
        $catalog->defaultSite = 1;

        $this->assertSame([['o:id' => 1]], $this->importer($catalog)->withSites(['o:site' => [['o:slug' => 'x']]], [])['o:site']);
    }

    public function testNoSitesWithoutADefaultSiteLeavesItToOmeka(): void
    {
        $this->assertArrayNotHasKey('o:site', $this->importer()->withSites(['o:site' => [['o:slug' => 'x']]], []));
    }

    public function testNullSitesKeepTheDocument(): void
    {
        $document = ['o:site' => [['o:slug' => 'x']]];
        $this->assertSame($document, $this->importer()->withSites($document, null));
    }

    public function testContextUrlIsFetched(): void
    {
        $fetched = [];
        $importer = $this->importer(null, function (string $url) use (&$fetched) {
            $fetched[] = $url;
            return ['@context' => ['sdo' => 'https://schema.org/']];
        });
        $result = $importer->import(
            ['@context' => 'http://source/api-context', 'sdo:name' => [['type' => 'literal', '@value' => 'X']]],
            ResourceType::Item,
            'x',
            dryRun: true
        );
        $this->assertSame(['http://source/api-context'], $fetched);
        $this->assertSame('would create', $result->status());
        $this->assertNull($result->id());
    }

    public function testUnreachableContextFallsBackToTheTargetPrefixes(): void
    {
        $importer = $this->importer(null, fn(string $url) => throw new \RuntimeException('offline'));
        $result = $importer->import(
            ['@context' => 'http://source/api-context', 'dcterms:title' => [['type' => 'literal', '@value' => 'New']]],
            ResourceType::Item,
            'x',
            dryRun: true
        );
        $this->assertSame('would create', $result->status());
        $this->assertSame('New', $result->title());
    }

    public function testExistingIdentitySkips(): void
    {
        $doc = ['o-ref:id' => ['dcterms:identifier' => 'obj-42'], 'dcterms:title' => [['type' => 'literal', '@value' => 'Lady with an Ermine']]];
        $result = $this->importer()->import($doc, ResourceType::Item, 'x');
        $this->assertSame('exists', $result->status());
        $this->assertSame(42, $result->id());
        $this->assertSame(['status' => 'exists', 'id' => 42, 'title' => 'Lady with an Ermine', 'warnings' => []], $result->toArray());
    }

    public function testExistingTitleSkips(): void
    {
        $doc = ['dcterms:title' => [['type' => 'literal', '@value' => 'Lady with an Ermine']]];
        $this->assertSame(42, $this->importer()->import($doc, ResourceType::Item, 'x')->id());
    }

    public function testAmbiguousTitleSkips(): void
    {
        $catalog = FakeCatalog::sample();
        $catalog->titles['items'][43] = 'Lady with an Ermine';
        $result = $this->importer($catalog)->import(['o:title' => 'Lady with an Ermine'], ResourceType::Item, 'x');
        $this->assertSame('ambiguous', $result->status());
        $this->assertSame(2, $result->count());
    }

    public function testItemSetsAreLookedUpAsItemSets(): void
    {
        $result = $this->importer()->import(['o-ref:id' => ['dcterms:identifier' => 'set-1']], ResourceType::ItemSet, 'x');
        $this->assertSame('exists', $result->status());
        $this->assertSame(9, $result->id());
    }

    public function testMediaOnItemSetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Media can only be added to items.');
        $this->importer()->import(
            ['o:title' => 'New set'],
            ResourceType::ItemSet,
            'x',
            [MediaConfig::fromArray(['ingester' => 'url', 'source' => 'https://example.org/a.jpg'])]
        );
    }

    public function testCreatingWithoutAnApiIsALogicError(): void
    {
        $this->expectException(LogicException::class);
        $this->importer()->import(['o:title' => 'Brand new'], ResourceType::Item, 'x');
    }

    public function testMediaProblemsStopTheImportBeforeAnythingIsCreated(): void
    {
        $checked = [];
        $importer = new ResourceImporter(null, FakeCatalog::sample(), null, function (array $media) use (&$checked) {
            $checked = $media;
            return ['media[1]: Cannot store files with the resolved extension "sh".'];
        });
        $media = [MediaConfig::fromArray(['ingester' => 'upload', 'source' => '/tmp/a.sh'])];
        try {
            $importer->import(['o:title' => 'Brand new'], ResourceType::Item, 'new.jsonld', $media);
            $this->fail('expected ResourceImportException');
        } catch (ResourceImportException $e) {
            $this->assertSame('new.jsonld: media[1]: Cannot store files with the resolved extension "sh".', $e->getMessage());
        }
        $this->assertSame($media, $checked);
    }

    public function testMediaAreNotCheckedForAnExistingItem(): void
    {
        $importer = new ResourceImporter(null, FakeCatalog::sample(), null, fn(array $media) => $this->fail('checked'));
        $media = [MediaConfig::fromArray(['ingester' => 'upload', 'source' => '/tmp/a.sh'])];
        $result = $importer->import(['o:title' => 'Lady with an Ermine'], ResourceType::Item, 'x', $media);
        $this->assertSame('exists', $result->status());
    }
}
