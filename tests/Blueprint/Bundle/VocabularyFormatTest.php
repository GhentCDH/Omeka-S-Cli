<?php
namespace Tests\Blueprint\Bundle;

use OSC\Blueprint\Bundle\VocabularyFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VocabularyFormat::class)]
class VocabularyFormatTest extends TestCase
{
    public function testTheContentTypeWins(): void
    {
        $this->assertSame('turtle', VocabularyFormat::detect('text/turtle', 'https://example.org/v.rdf'));
        $this->assertSame('rdfxml', VocabularyFormat::detect('application/rdf+xml', 'https://example.org/v'));
        $this->assertSame('ntriples', VocabularyFormat::detect('application/n-triples', 'https://example.org/v'));
        $this->assertSame('jsonld', VocabularyFormat::detect('application/ld+json', 'https://example.org/v'));
    }

    public function testAGenericContentTypeFallsBackToTheExtension(): void
    {
        // raw.githubusercontent.com serves every file as text/plain
        $this->assertSame('turtle', VocabularyFormat::detect('text/plain', 'https://raw.githubusercontent.com/o/r/main/v.ttl'));
        $this->assertSame('rdfxml', VocabularyFormat::detect(null, '/bundle/vocabularies/v.owl'));
        $this->assertSame('ntriples', VocabularyFormat::detect(null, 'https://example.org/v.nt?download=1'));
        $this->assertNull(VocabularyFormat::detect('text/plain', 'https://example.org/vocab'));
    }

    public function testExtension(): void
    {
        $this->assertSame('ttl', VocabularyFormat::extension('turtle', 'https://example.org/v'));
        $this->assertSame('rdf', VocabularyFormat::extension('rdfxml', 'https://example.org/v.owl'));
        $this->assertSame('n3', VocabularyFormat::extension(null, 'https://example.org/v.n3'));
        $this->assertSame('vocab', VocabularyFormat::extension(null, 'https://example.org/vocab'));
    }
}
