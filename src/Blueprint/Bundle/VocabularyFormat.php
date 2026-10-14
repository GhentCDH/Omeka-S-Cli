<?php
namespace OSC\Blueprint\Bundle;

/**
 * The RDF format of a downloaded vocabulary (one of VocabularyConfig::VALID_FORMATS), so a bundle
 * can pin it: once the file is local, the server's Content-Type is gone.
 */
final class VocabularyFormat
{
    private const BY_CONTENT_TYPE = [
        'text/turtle'           => 'turtle',
        'application/x-turtle'  => 'turtle',
        'application/rdf+xml'   => 'rdfxml',
        'application/n-triples' => 'ntriples',
        'application/ld+json'   => 'jsonld',
    ];

    private const BY_EXTENSION = [
        'ttl'    => 'turtle',
        'rdf'    => 'rdfxml',
        'owl'    => 'rdfxml',
        'xml'    => 'rdfxml',
        'nt'     => 'ntriples',
        'jsonld' => 'jsonld',
    ];

    private const EXTENSIONS = ['turtle' => 'ttl', 'rdfxml' => 'rdf', 'ntriples' => 'nt', 'jsonld' => 'jsonld'];

    /** The format from the Content-Type, else from the source's file extension; null when unknown. */
    public static function detect(?string $contentType, string $source): ?string
    {
        if ($contentType !== null && isset(self::BY_CONTENT_TYPE[$contentType])) {
            return self::BY_CONTENT_TYPE[$contentType];
        }
        return self::BY_EXTENSION[self::sourceExtension($source)] ?? null;
    }

    /** The file extension for a bundled copy: the format's, else the source's own, else `rdf`. */
    public static function extension(?string $format, string $source): string
    {
        if ($format !== null && isset(self::EXTENSIONS[$format])) {
            return self::EXTENSIONS[$format];
        }
        $extension = self::sourceExtension($source);
        return $extension !== '' && preg_match('/^[a-z0-9]+$/', $extension) === 1 ? $extension : 'rdf';
    }

    private static function sourceExtension(string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH);
        return strtolower(pathinfo(is_string($path) ? $path : $source, PATHINFO_EXTENSION));
    }
}
