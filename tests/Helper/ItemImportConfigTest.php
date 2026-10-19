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
    public function testSourceIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'source'");
        ItemImportConfig::fromArray(['title' => 'x']);
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
