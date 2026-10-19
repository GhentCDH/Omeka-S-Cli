<?php
namespace OSC\Blueprint;

use OSC\Helper\MediaConfig;
use OSC\Resource\PortableJsonLd;

/**
 * Map the shared blueprint format's simple `itemSets` / `items` onto portable JSON-LD, so they are
 * imported the same way as the CLI's own JSON-LD entries:
 * - `title`, `description`, `creator` → Dublin Core literals;
 * - `itemSets` (titles) → `o:item_set` references by `dcterms:title`;
 * - `sites` are not mapped here: they travel through the import config (ItemImportConfig::sites()),
 *   where no sites means the default site;
 * - `media[]` `{url, title, altText}`: an http(s) URL → the `url` ingester, anything else (a path the
 *   loader resolved) → `upload`.
 *
 * The documents carry no `o-ref:id`, so an existing resource is found by its title. Pure: no IO.
 */
final class SimpleContentMapper
{
    private const DCTERMS = 'http://purl.org/dc/terms/';

    /** Simple field => Dublin Core term. */
    private const PROPERTIES = ['title' => 'dcterms:title', 'description' => 'dcterms:description', 'creator' => 'dcterms:creator'];

    /** A simple item set as a JSON-LD document. */
    public static function itemSet(array $entry): array
    {
        return self::document('o:ItemSet', $entry);
    }

    /**
     * A simple item as a JSON-LD document and its media.
     *
     * @return array{0: array, 1: MediaConfig[]}
     */
    public static function item(array $entry): array
    {
        $doc = self::document('o:Item', $entry);
        $itemSets = array_values(array_filter((array) ($entry['itemSets'] ?? []), 'is_string'));
        if ($itemSets) {
            $doc['o:item_set'] = array_map(
                fn(string $title) => [PortableJsonLd::PREFIX . ':' . PortableJsonLd::REF => ['dcterms:title' => $title]],
                $itemSets
            );
        }

        $media = [];
        foreach ((array) ($entry['media'] ?? []) as $simple) {
            if (!is_array($simple) || !isset($simple['url']) || !is_string($simple['url'])) {
                continue;
            }
            $isLink = preg_match('#^https?://#i', trim($simple['url'])) === 1;
            $media[] = MediaConfig::fromArray(array_filter([
                'ingester' => $isLink ? 'url' : 'upload',
                'source' => $simple['url'],
                'title' => is_string($simple['title'] ?? null) ? $simple['title'] : null,
                'altText' => is_string($simple['altText'] ?? null) ? $simple['altText'] : null,
            ], fn($value) => $value !== null));
        }
        return [$doc, $media];
    }

    private static function document(string $type, array $entry): array
    {
        $doc = [
            '@context' => ['dcterms' => self::DCTERMS, 'o' => PortableJsonLd::O_NS, PortableJsonLd::PREFIX => PortableJsonLd::NS],
            '@type' => $type,
        ];
        foreach (self::PROPERTIES as $field => $term) {
            if (isset($entry[$field]) && is_string($entry[$field]) && trim($entry[$field]) !== '') {
                $doc[$term] = [['type' => 'literal', '@value' => $entry[$field]]];
            }
        }
        return $doc;
    }
}
