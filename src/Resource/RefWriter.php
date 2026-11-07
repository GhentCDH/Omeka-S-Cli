<?php
namespace OSC\Resource;

/**
 * Writes o-ref:ref objects, the portable counterpart of {"o:id": N} (see RefResolver): a record named by
 * its value for one property (the term), so the reference survives a move to another instance.
 */
final class RefWriter
{
    /** @param string $term The property that identifies the records, e.g. dcterms:identifier */
    public function __construct(private Catalog $catalog, private string $term)
    {
    }

    /**
     * {"o-ref:ref": {term: value}} for a record, or null when it has no value for the term. The value is
     * the record's first one: a URI value gives its URI, any other value its literal.
     */
    public function write(string $resourceName, int $id): ?array
    {
        $value = $this->catalog->refValue($resourceName, $id, $this->term);
        if ($value === null || $value === '') {
            return null;
        }
        return [PortableJsonLd::PREFIX . ':' . PortableJsonLd::REF => [$this->term => $value]];
    }

    /** The @context entries the written refs need: o, o-ref and the namespace of the term's prefix. */
    public function context(): array
    {
        $context = ['o' => PortableJsonLd::O_NS, PortableJsonLd::PREFIX => PortableJsonLd::NS];
        $parts = PortableJsonLd::splitTerm($this->term);
        $namespace = $parts === null ? null : $this->catalog->namespaceForPrefix($parts[0]);
        if ($namespace !== null) {
            $context[$parts[0]] = $namespace;
        }
        ksort($context);
        return $context;
    }
}
