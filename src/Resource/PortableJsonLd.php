<?php
namespace OSC\Resource;

/**
 * The portable JSON-LD format: Omeka's JSON-LD with an inline @context and, instead of instance ids,
 * references that name a property and a value to look a record up by. Those use the `o-ref`
 * vocabulary (a separate omeka-s-contrib project): `ref` (a link to another record) and `id` (the
 * record's own identity), both shaped `{ "<term>": "<value>" }`.
 *
 * Keys of that vocabulary are recognized by namespace URI, not by prefix: the prefix is a local alias
 * a document may choose.
 */
final class PortableJsonLd
{
    public const NS = 'https://omeka-s-contrib.github.io/omeka-s-ref/ns#';
    public const PREFIX = 'o-ref';
    public const REF = 'ref';
    public const ID = 'id';

    /** The Omeka vocabulary (o:Item, o:title, ...). */
    public const O_NS = 'http://omeka.org/s/vocabs/o#';

    /** Keys whose value objects mark a value (as opposed to an o:* object or module data). */
    private const VALUE_KEYS = ['type', '@value', '@id', 'property_id', 'value_resource_id'];

    /**
     * An inline @context as a prefix => namespace map. A context URL (or anything that is not an
     * object) gives an empty map; entries that are not plain strings are ignored.
     *
     * @return array<string,string>
     */
    public static function contextMap(mixed $context): array
    {
        if (!is_array($context)) {
            return [];
        }
        return array_filter($context, fn($value, $key) => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The document's own key for the o-ref term $local ('ref' or 'id'): the prefix its context maps to
     * the o-ref namespace, else the default prefix.
     *
     * @param array<string,string> $context
     */
    public static function keyFor(array $context, string $local): ?string
    {
        $prefix = array_search(self::NS, $context, true);
        return (is_string($prefix) ? $prefix : self::PREFIX) . ':' . $local;
    }

    /**
     * Whether a value under a term key is a list of value objects (a property's values), rather than
     * an object such as {"o:term": ...} or a module's data.
     */
    public static function isValueList(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (!is_array($entry) || !self::isValueObject($entry)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A vocabulary term split into prefix and local name, or null when the key is not a property
     * term: a JSON-LD keyword, an o:* key, or anything without exactly one colon.
     *
     * @return array{0:string,1:string}|null
     */
    public static function splitTerm(string $key): ?array
    {
        $parts = explode(':', $key);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || $parts[0] === 'o' || str_starts_with($key, '@')) {
            return null;
        }
        return [$parts[0], $parts[1]];
    }

    private static function isValueObject(array $entry): bool
    {
        foreach (self::VALUE_KEYS as $key) {
            if (array_key_exists($key, $entry)) {
                return true;
            }
        }
        foreach (array_keys($entry) as $key) {
            if (is_string($key) && str_ends_with($key, ':' . self::REF)) {
                return true;
            }
        }
        return false;
    }
}
