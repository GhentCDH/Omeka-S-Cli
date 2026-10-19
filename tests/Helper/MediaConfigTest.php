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

    public function testAnUploadNeedsASource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("An upload media needs a 'source'.");
        MediaConfig::fromArray(['ingester' => 'upload']);
    }

    public function testAnIngesterIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A media needs a non-empty 'ingester'.");
        MediaConfig::fromArray(['source' => 'https://example.org/a.jpg']);
    }

    public function testOtherIngestersNeedNoSource(): void
    {
        // a module ingester with its own key: the ingester itself reports what is missing
        $this->assertSame(
            ['o:ingester' => 'sideload', 'o:is_public' => true, 'ingest_filename' => 'a.jpg'],
            MediaConfig::fromArray(['ingester' => 'sideload', 'ingest_filename' => 'a.jpg'])->toApiPayload()
        );
    }

    public function testUnknownKeysArePassedToTheIngester(): void
    {
        $payload = MediaConfig::fromArray([
            'ingester' => 'youtube', 'source' => 'https://youtu.be/abc', 'start' => '30', 'end' => 60, 'store_original' => false,
        ])->toApiPayload();
        $this->assertSame('https://youtu.be/abc', $payload['o:source']);
        $this->assertSame('30', $payload['start']);
        $this->assertSame(60, $payload['end']);
        $this->assertFalse($payload['store_original']);
    }

    public function testPropertyTermsUseTheSimpleNotation(): void
    {
        $payload = MediaConfig::fromArray([
            'ingester' => 'html', 'source' => '<p>x</p>',
            'dcterms:description' => 'One',
            'dcterms:subject' => ['Art', 'Paint'],
            'dcterms:source' => [['type' => 'uri', '@id' => 'https://example.org', 'o:label' => 'Example']],
        ])->toApiPayload();
        $this->assertSame([['type' => 'literal', 'property_id' => 'auto', '@value' => 'One']], $payload['dcterms:description']);
        $this->assertSame([
            ['type' => 'literal', 'property_id' => 'auto', '@value' => 'Art'],
            ['type' => 'literal', 'property_id' => 'auto', '@value' => 'Paint'],
        ], $payload['dcterms:subject']);
        $this->assertSame(
            [['type' => 'uri', '@id' => 'https://example.org', 'o:label' => 'Example', 'property_id' => 'auto']],
            $payload['dcterms:source']
        );
    }

    public function testAnInvalidPropertyValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A media 'dcterms:description' must be a string, a list of strings or JSON-LD values.");
        MediaConfig::fromArray(['ingester' => 'html', 'source' => 'x', 'dcterms:description' => 42]);
    }

    public function testARawKeyWinsOverAMappedOne(): void
    {
        $payload = MediaConfig::fromArray([
            'ingester' => 'url', 'source' => 'https://example.org/a.jpg', 'title' => 'Mapped', 'public' => false,
            'dcterms:title' => 'Raw', 'o:is_public' => true, 'o:source' => 'Original name',
        ])->toApiPayload();
        $this->assertSame([['type' => 'literal', 'property_id' => 'auto', '@value' => 'Raw']], $payload['dcterms:title']);
        $this->assertTrue($payload['o:is_public']);
        $this->assertSame('Original name', $payload['o:source']);
        $this->assertSame('https://example.org/a.jpg', $payload['ingest_url']);
    }

    public function testPublicIsMapped(): void
    {
        $this->assertFalse(MediaConfig::fromArray(['ingester' => 'html', 'source' => 'x', 'public' => false])->toApiPayload()['o:is_public']);
    }

    public function testToArrayRoundTripsEveryKey(): void
    {
        $data = ['ingester' => 'youtube', 'source' => 'https://youtu.be/abc', 'title' => 'Clip', 'altText' => 'Alt',
            'public' => false, 'start' => '30', 'dcterms:description' => 'One'];
        $this->assertSame($data, MediaConfig::fromArray($data)->toArray());
        $this->assertEquals(MediaConfig::fromArray($data), MediaConfig::fromArray(MediaConfig::fromArray($data)->toArray()));
    }

    public function testSpecIngesterAndSource(): void
    {
        $this->assertSame(['ingester' => 'upload', 'source' => './a.jpg'], MediaConfig::parseSpec('upload=./a.jpg'));
    }

    public function testSpecOptions(): void
    {
        $this->assertSame(
            ['ingester' => 'youtube', 'source' => 'https://youtu.be/abc', 'start' => '30', 'end' => '60', 'title' => 'A clip'],
            MediaConfig::parseSpec('youtube=https://youtu.be/abc;start=30;end=60;title=A clip')
        );
        $this->assertSame(
            ['ingester' => 'url', 'source' => 'https://example.org/a.jpg', 'public' => false],
            MediaConfig::parseSpec('url=https://example.org/a.jpg;public=false')
        );
    }

    public function testSpecEscapedSemicolon(): void
    {
        $this->assertSame(
            ['ingester' => 'html', 'source' => '<p>a;b</p>', 'title' => 'x;y'],
            MediaConfig::parseSpec('html=<p>a\\;b</p>;title=x\\;y')
        );
    }

    public function testSpecWithoutSource(): void
    {
        $this->assertSame(['ingester' => 'sideload', 'ingest_filename' => 'a.jpg'], MediaConfig::parseSpec('sideload=;ingest_filename=a.jpg'));
    }

    public function testSpecAsJson(): void
    {
        $this->assertSame(
            ['ingester' => 'youtube', 'source' => 'https://youtu.be/abc', 'start' => 30],
            MediaConfig::parseSpec('{"ingester": "youtube", "source": "https://youtu.be/abc", "start": 30}')
        );
    }

    public function testInvalidSpecs(): void
    {
        foreach (['upload', 'url=https://x.org/a.jpg;title', '{"ingester": ', '["upload"]', '=x'] as $spec) {
            try {
                MediaConfig::parseSpec($spec);
                $this->fail("'{$spec}' must be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('--media', $e->getMessage(), $spec);
            }
        }
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
