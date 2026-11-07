<?php
namespace OSC\Resource;

/**
 * Resolves o-ref:ref objects (https://omeka-s-contrib.github.io/omeka-s-ref/ns#rule-ref) to the ids of
 * this instance. Wherever Omeka S writes {"o:id": N}, a portable document writes
 * {"o-ref:ref": {"<term>": "<value>"}}: the record that has that value for that property.
 *
 * - The ref key is found by the o-ref namespace in the document's @context, so any prefix works;
 *   without a context the default "o-ref" prefix is used.
 * - The term is mapped to the prefix this instance uses for the same namespace.
 * - The caller names the resource type (the position of the ref decides it) and decides what an
 *   unresolved reference means.
 */
final class RefResolver
{
    /** @param array<string,string> $context The document's @context as a prefix => namespace map */
    public function __construct(private Catalog $catalog, private array $context)
    {
    }

    public static function forDocument(Catalog $catalog, array $document): self
    {
        return new self($catalog, PortableJsonLd::contextMap($document['@context'] ?? null));
    }

    /** The document's own key for o-ref:ref. */
    public function refKey(): string
    {
        return PortableJsonLd::keyFor($this->context, PortableJsonLd::REF);
    }

    /** Whether $node is a reference object (it has the ref key). */
    public function isRef(mixed $node): bool
    {
        return is_array($node) && array_key_exists($this->refKey(), $node);
    }

    /**
     * Resolve a reference object ({"o-ref:ref": {...}}, other keys such as o:title are ignored).
     *
     * @param string $resourceName The API resource the reference points to (items, item_sets, media)
     */
    public function resolve(mixed $node, string $resourceName): RefResolution
    {
        $ref = is_array($node) ? ($node[$this->refKey()] ?? null) : null;
        if (!is_array($ref) || count($ref) !== 1 || !is_string(array_key_first($ref)) || !is_scalar(reset($ref))) {
            return RefResolution::invalid($resourceName);
        }
        $term = (string) array_key_first($ref);
        $value = (string) reset($ref);
        if ($term === '' || $value === '') {
            return RefResolution::invalid($resourceName);
        }
        $ids = $this->catalog->findByValue($resourceName, $this->mapTerm($term)[0] ?? $term, $value);
        return RefResolution::fromMatches($resourceName, $term, $value, $ids);
    }

    /**
     * A document term mapped to this instance's term for the same namespace: the prefix is looked up
     * in the document's @context, else taken as one of this instance's prefixes.
     *
     * @return array{0:?string,1:string,2:?string} [target term or null, the reason when null, namespace]
     */
    public function mapTerm(string $term): array
    {
        [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
        $namespace = $this->context[$prefix] ?? $this->catalog->namespaceForPrefix($prefix);
        if ($namespace === null) {
            return [null, ": unknown prefix \"{$prefix}\" (not in the file's @context, and no vocabulary with this prefix is installed)", null];
        }
        $target = $this->catalog->prefixForNamespace($namespace);
        if ($target === null) {
            return [null, " uses vocabulary {$namespace}, which is not installed (vocabulary:import)", $namespace];
        }
        return ["{$target}:{$local}", '', $namespace];
    }
}
