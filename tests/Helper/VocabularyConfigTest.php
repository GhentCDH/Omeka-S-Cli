<?php
namespace Tests\Helper;

use InvalidArgumentException;
use OSC\Helper\VocabularyConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VocabularyConfig::class)]
class VocabularyConfigTest extends TestCase
{
    /** @return array<string,string> A minimal set of the required identifying fields */
    private function identity(): array
    {
        return ['label' => 'Example', 'namespaceUri' => 'http://example.org/ns#', 'prefix' => 'ex'];
    }

    public function testSourceUrlMapsToUrlStrategy(): void
    {
        $config = VocabularyConfig::fromArray(['source' => 'https://example.org/ns.rdf'] + $this->identity());
        $options = $config->toImporterOptions();

        $this->assertSame('url', $options['strategy']);
        $this->assertSame('https://example.org/ns.rdf', $options['options']['url']);
        $this->assertArrayNotHasKey('file', $options['options']);
        $this->assertNull($config->deprecatedSourceKey());
    }

    public function testSourceAbsolutePathMapsToFileStrategy(): void
    {
        $config = VocabularyConfig::fromArray(['source' => '/abs/path/ns.ttl'] + $this->identity());
        $options = $config->toImporterOptions();

        $this->assertSame('file', $options['strategy']);
        $this->assertSame('/abs/path/ns.ttl', $options['options']['file']);
        $this->assertNull($config->deprecatedSourceKey());
    }

    public function testLegacyUrlStillWorksAndIsFlaggedDeprecated(): void
    {
        $config = VocabularyConfig::fromArray(['url' => 'https://example.org/ns.rdf'] + $this->identity());
        $this->assertSame('url', $config->toImporterOptions()['strategy']);
        $this->assertSame('url', $config->deprecatedSourceKey());
    }

    public function testLegacyFileStillWorksAndIsFlaggedDeprecated(): void
    {
        $config = VocabularyConfig::fromArray(['file' => '/abs/ns.ttl'] + $this->identity());
        $this->assertSame('file', $config->toImporterOptions()['strategy']);
        $this->assertSame('file', $config->deprecatedSourceKey());
    }

    public function testMissingSourceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray($this->identity());
    }

    public function testEmptySourceCountsAsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray(['source' => '   '] + $this->identity());
    }

    public function testSourceCombinedWithLegacyKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray(
            ['source' => '/a.ttl', 'url' => 'https://example.org/ns.rdf'] + $this->identity()
        );
    }

    public function testBothLegacyFileAndUrlThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray(
            ['file' => '/a.ttl', 'url' => 'https://example.org/ns.rdf'] + $this->identity()
        );
    }

    public function testMissingLabelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray([
            'source' => '/a.ttl', 'namespaceUri' => 'http://example.org/ns#', 'prefix' => 'ex',
        ]);
    }

    public function testMissingNamespaceOrPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray(['source' => '/a.ttl', 'label' => 'Example', 'prefix' => 'ex']);
    }

    public function testInvalidFormatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VocabularyConfig::fromArray(['source' => '/a.ttl', 'format' => 'bogus'] + $this->identity());
    }

    public function testAutoFormatBecomesGuessInImporterOptions(): void
    {
        $config = VocabularyConfig::fromArray(['source' => '/a.ttl'] + $this->identity());
        $this->assertSame('guess', $config->toImporterOptions()['options']['format']);
    }

    public function testExplicitFormatIsLowercasedAndPassedThrough(): void
    {
        $config = VocabularyConfig::fromArray(['source' => '/a.ttl', 'format' => 'Turtle'] + $this->identity());
        $this->assertSame('turtle', $config->toImporterOptions()['options']['format']);
    }

    public function testOptionalFieldsAreMapped(): void
    {
        $config = VocabularyConfig::fromArray([
            'source' => '/a.ttl',
            'comment' => 'A comment',
            'lang' => 'en',
            'labelProperty' => 'rdfs:label',
            'commentProperty' => 'rdfs:comment',
        ] + $this->identity());

        $options = $config->toImporterOptions();
        $this->assertSame('A comment', $options['vocabulary']['o:comment']);
        $this->assertSame('en', $options['options']['lang']);
        $this->assertSame('rdfs:label', $options['options']['label_property']);
        $this->assertSame('rdfs:comment', $options['options']['comment_property']);
    }

    public function testToArrayEmitsSourceOmitsNullsAndAutoFormat(): void
    {
        // built from a legacy url, toArray() migrates it to `source` and drops the default format
        $config = VocabularyConfig::fromArray(
            ['url' => 'https://example.org/ns.rdf', 'format' => 'auto'] + $this->identity()
        );

        $this->assertSame([
            'source' => 'https://example.org/ns.rdf',
            'label' => 'Example',
            'namespaceUri' => 'http://example.org/ns#',
            'prefix' => 'ex',
        ], $config->toArray());
    }

    public function testIsValidFormat(): void
    {
        $this->assertTrue(VocabularyConfig::isValidFormat(null));
        $this->assertTrue(VocabularyConfig::isValidFormat('RDFXML'));
        $this->assertFalse(VocabularyConfig::isValidFormat('bogus'));
    }
}
