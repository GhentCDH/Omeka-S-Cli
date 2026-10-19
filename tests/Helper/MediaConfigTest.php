<?php

namespace Tests\Helper;

use InvalidArgumentException;
use OSC\Helper\MediaConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaConfig::class)]
class MediaConfigTest extends TestCase
{
    public function testPayloadPerIngester(): void
    {
        $this->assertSame(
            ['o:ingester' => 'upload', 'o:is_public' => true, 'ingest_source' => '/tmp/a.jpg'],
            MediaConfig::fromArray(['ingester' => 'upload', 'source' => '/tmp/a.jpg'])->toApiPayload()
        );
        $this->assertSame(
            ['o:ingester' => 'url', 'o:is_public' => true, 'ingest_url' => 'https://example.org/a.jpg'],
            MediaConfig::fromArray(['ingester' => 'url', 'source' => 'https://example.org/a.jpg'])->toApiPayload()
        );
        $this->assertSame(
            ['o:ingester' => 'html', 'o:is_public' => true, 'html' => '<p>Hi</p>'],
            MediaConfig::fromArray(['ingester' => 'html', 'source' => '<p>Hi</p>'])->toApiPayload()
        );
        $this->assertSame(
            ['o:ingester' => 'iiif', 'o:is_public' => true, 'o:source' => 'https://example.org/iiif/info.json'],
            MediaConfig::fromArray(['ingester' => 'iiif', 'source' => 'https://example.org/iiif/info.json'])->toApiPayload()
        );
    }

    public function testTitleAndAltText(): void
    {
        $payload = MediaConfig::fromArray([
            'ingester' => 'url', 'source' => 'https://example.org/a.jpg', 'title' => 'Front', 'altText' => 'A painting',
        ])->toApiPayload();
        $this->assertSame([['type' => 'literal', 'property_id' => 'auto', '@value' => 'Front']], $payload['dcterms:title']);
        $this->assertSame('A painting', $payload['o:alt_text']);
    }

    public function testMissingSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'source'");
        MediaConfig::fromArray(['ingester' => 'url']);
    }

    public function testOnlyUploadIsAFile(): void
    {
        $this->assertTrue(MediaConfig::fromArray(['ingester' => 'upload', 'source' => 'a.jpg'])->isFile());
        $this->assertFalse(MediaConfig::fromArray(['ingester' => 'url', 'source' => 'https://x.org/a.jpg'])->isFile());
    }

    public function testToArrayDropsUnsetFields(): void
    {
        $this->assertSame(
            ['ingester' => 'url', 'source' => 'https://x.org/a.jpg', 'title' => 'T'],
            MediaConfig::fromArray(['ingester' => 'url', 'source' => 'https://x.org/a.jpg', 'title' => 'T'])->toArray()
        );
    }
}
