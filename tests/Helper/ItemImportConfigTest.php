<?php

namespace Tests\Helper;

use InvalidArgumentException;
use OSC\Helper\ItemImportConfig;
use OSC\Helper\MediaConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemImportConfig::class)]
#[UsesClass(MediaConfig::class)]
class ItemImportConfigTest extends TestCase
{
    public function testASourceOrATitleIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("An import entry needs a 'source' (a JSON-LD file) or a 'title'.");
        ItemImportConfig::fromArray(['itemSets' => ['Postcards']]);
    }

    public function testATitleOnlyEntryIsAccepted(): void
    {
        $data = ['title' => 'Market square', 'itemSets' => ['Postcards'], 'sites' => ['collections'],
            'media' => [['ingester' => 'upload', 'source' => 'm.png']]];
        $config = ItemImportConfig::fromArray($data);

        $this->assertFalse($config->hasSource());
        $this->assertNull($config->source());
        $this->assertSame('Market square', $config->title());
        $this->assertSame(['Postcards'], $config->itemSets());
        $this->assertSame(['collections'], $config->sites());
        $this->assertSame($data, $config->toArray());
    }

    public function testATitleOnlyEntryWithoutSitesGoesToTheDefaultSite(): void
    {
        $this->assertSame([], ItemImportConfig::fromArray(['title' => 'Market square'])->sites());
    }

    public function testWithoutASourceOnlyTitleItemSetsSitesAndMediaAreAllowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Without a source an entry takes only title, itemSets, sites and media; put other properties ('description') in a JSON-LD source."
        );
        ItemImportConfig::fromArray(['title' => 'Market square', 'description' => 'A square']);
    }

    public function testWithASourceItemSetsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("An import entry with a 'source' takes no 'itemSets': the source file sets the item sets.");
        ItemImportConfig::fromArray(['source' => 'a.jsonld', 'itemSets' => ['Postcards']]);
    }

    public function testWithASourceUnknownKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("An import entry with a 'source' takes only title, sites and media; not 'creator'.");
        ItemImportConfig::fromArray(['source' => 'a.jsonld', 'creator' => 'Leonardo']);
    }

    public function testWithSourcesSkipsAMissingSource(): void
    {
        $config = ItemImportConfig::fromArray(['title' => 'T', 'media' => [['ingester' => 'upload', 'source' => 'm.png']]])
            ->withSources(fn(string $s) => "/abs/{$s}");
        $this->assertNull($config->source());
        $this->assertSame('/abs/m.png', $config->media()[0]->source());
    }

    public function testSitesAreValidatedAndRoundTripped(): void
    {
        $config = ItemImportConfig::fromArray(['source' => 'a.jsonld', 'sites' => ['collections', 'Archive']]);
        $this->assertSame(['collections', 'Archive'], $config->sites());
        $this->assertSame(['source' => 'a.jsonld', 'sites' => ['collections', 'Archive']], $config->toArray());

        $this->assertNull(ItemImportConfig::fromArray(['source' => 'a.jsonld'])->sites());
        $this->assertSame([], ItemImportConfig::fromArray(['source' => 'a.jsonld', 'sites' => []])->sites());
    }

    public function testSitesMustBeAListOfNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("An import entry 'sites' must be a list of site slugs or titles.");
        ItemImportConfig::fromArray(['source' => 'a.jsonld', 'sites' => ['collections', 3]]);
    }

    public function testMediaErrorsNameTheEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('media[1]: ');
        ItemImportConfig::fromArray(['source' => 'a.jsonld', 'media' => [
            ['ingester' => 'url', 'source' => 'https://x.org/a'],
            ['ingester' => 'upload'], // an upload needs a source; other ingesters may not
        ]]);
    }

    public function testWithSourcesResolvesOnlyFiles(): void
    {
        $config = ItemImportConfig::fromArray(['source' => 'a.jsonld', 'title' => 'A', 'media' => [
            ['ingester' => 'upload', 'source' => 'm/a.jpg'],
            ['ingester' => 'url', 'source' => 'https://x.org/b.jpg'],
        ]])->withSources(fn(string $s) => '/base/' . $s);
        $this->assertSame('/base/a.jsonld', $config->source());
        $this->assertSame('A', $config->title());
        $this->assertSame('/base/m/a.jpg', $config->media()[0]->source());
        $this->assertSame('https://x.org/b.jpg', $config->media()[1]->source());
    }

    public function testToArrayRoundTrips(): void
    {
        $data = ['source' => 'a.jsonld', 'title' => 'A', 'media' => [['ingester' => 'upload', 'source' => 'a.jpg']]];
        $this->assertSame($data, ItemImportConfig::fromArray($data)->toArray());
        $this->assertSame(['source' => 'a.jsonld'], ItemImportConfig::fromArray(['source' => 'a.jsonld'])->toArray());
    }
}
