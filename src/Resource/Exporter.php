<?php
namespace OSC\Resource;

/**
 * Turn a resource's Omeka JSON-LD into the portable format: the context inlined, the instance's ids
 * replaced by references another instance can resolve (class term, template label, site slug, and
 * o-ref:ref / o-ref:id naming a property value), and the API's output-only keys dropped.
 *
 * Only core fields and properties are exported; module data (e.g. Mapping's) is left out, with a
 * warning.
 */
final class Exporter
{
    /** Kept per value; everything else is output-only or instance-specific. */
    private const VALUE_KEYS = ['type', 'is_public', '@value', '@language', '@id', 'o:label', 'o:lang', '@annotation'];

    private const RESOURCE_WORDS = ['items' => 'item', 'item_sets' => 'item set', 'media' => 'media'];

    /** @var string[] */
    private array $warnings = [];
    private string $subject = '';

    /** @param string $referenceBy The property that identifies records, in o-ref:ref and o-ref:id */
    public function __construct(private Catalog $catalog, private string $referenceBy = 'dcterms:title')
    {
    }

    /** @param array $jsonLd The resource's representation, decoded to an array */
    public function export(array $jsonLd, ResourceType $type): ExportResult
    {
        $this->warnings = [];
        $title = is_string($jsonLd['o:title'] ?? null) ? $jsonLd['o:title'] : null;
        $this->subject = sprintf('"%s" (%s %s)', $title ?? '[untitled]', $type->label(), $jsonLd['o:id'] ?? '?');

        $properties = [];
        foreach ($jsonLd as $key => $values) {
            $term = is_string($key) ? PortableJsonLd::splitTerm($key) : null;
            if ($term === null) {
                continue;
            }
            if (!PortableJsonLd::isValueList($values) || $this->catalog->namespaceForPrefix($term[0]) === null) {
                $this->warnings[] = "{$this->subject}: \"{$key}\" is module data and is not exported";
                continue;
            }
            $values = $this->values($values, $key);
            if ($values) {
                $properties[$key] = $values;
            }
        }

        $doc = ['@context' => null];
        if (isset($jsonLd['@type'])) {
            $doc['@type'] = $jsonLd['@type'];
        }
        $identity = $this->firstValue($jsonLd[$this->referenceBy] ?? null);
        if ($identity !== null) {
            $doc[PortableJsonLd::PREFIX . ':' . PortableJsonLd::ID] = [$this->referenceBy => $identity];
        } elseif ($title !== null) {
            $this->warnings[] = "{$this->subject} has no {$this->referenceBy} value; it will be matched by title on import";
        } else {
            $this->warnings[] = "{$this->subject} has no {$this->referenceBy} value and no title; it cannot be matched on import and is created again each time";
        }
        $this->warnIfNotUnique($type, $identity, $title);
        if ($title !== null) {
            $doc['o:title'] = $title;
        }
        $doc['o:is_public'] = $jsonLd['o:is_public'] ?? true;
        if ($type === ResourceType::ItemSet && array_key_exists('o:is_open', $jsonLd)) {
            $doc['o:is_open'] = $jsonLd['o:is_open'];
        }
        $class = $this->id($jsonLd['o:resource_class'] ?? null);
        $classTerm = $class === null ? null : $this->catalog->resourceClassTerm($class);
        if ($classTerm !== null) {
            $doc['o:resource_class'] = ['o:term' => $classTerm];
        }
        $template = $this->id($jsonLd['o:resource_template'] ?? null);
        $templateLabel = $template === null ? null : $this->catalog->resourceTemplateLabel($template);
        if ($templateLabel !== null) {
            $doc['o:resource_template'] = ['o:label' => $templateLabel];
        }
        if ($type === ResourceType::Item) {
            $itemSets = $this->itemSets($jsonLd['o:item_set'] ?? []);
            if ($itemSets) {
                $doc['o:item_set'] = $itemSets;
            }
            $sites = $this->sites($jsonLd['o:site'] ?? []);
            if ($sites) {
                $doc['o:site'] = $sites;
            }
        }
        $doc += $properties;
        $doc['@context'] = $this->context($doc, $classTerm);

        return new ExportResult($doc, $this->warnings);
    }

    /**
     * Warn when what identifies the resource on import (its reference value, else its title) is shared
     * by other resources here: on import, it would be taken for one of them.
     */
    private function warnIfNotUnique(ResourceType $type, ?string $identity, ?string $title): void
    {
        if ($identity !== null) {
            $count = count($this->catalog->findByValue($type->value, $this->referenceBy, $identity));
            $what = "{$this->referenceBy} \"{$identity}\"";
        } elseif ($title !== null) {
            $count = count($this->catalog->findByTitle($type->value, $title));
            $what = "the title \"{$title}\"";
        } else {
            return;
        }
        if ($count > 1) {
            $this->warnings[] = "{$this->subject}: {$what} is not unique ({$count} {$type->label()}s); on import it can be taken for another one";
        }
    }

    private function values(array $values, string $key): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = $this->value($value, $key);
            if ($value !== null) {
                $out[] = $value;
            }
        }
        return $out;
    }

    private function value(array $value, string $key): ?array
    {
        $type = (string) ($value['type'] ?? '');
        $clean = array_intersect_key($value, array_flip(self::VALUE_KEYS));

        if (isset($value['value_resource_id']) && is_numeric($value['value_resource_id'])) {
            // a link: the linked record by the reference property, not by id or API URL
            unset($clean['@id']);
            $name = is_string($value['value_resource_name'] ?? null) ? $value['value_resource_name'] : 'items';
            $id = (int) $value['value_resource_id'];
            $match = $this->catalog->refValue($name, $id, $this->referenceBy);
            if ($match === null) {
                $word = self::RESOURCE_WORDS[$name] ?? $name;
                $this->warnings[] = "{$this->subject}: the link \"{$key}\" to {$word} {$id} has no {$this->referenceBy} value and is not exported";
                return null;
            }
            if ($type === 'resource') {
                $clean['value_resource_name'] = $name;
            }
            $clean[PortableJsonLd::PREFIX . ':' . PortableJsonLd::REF] = [$this->referenceBy => $match];
        }

        if (preg_match('/^customvocab:(\d+)$/', $type, $m)) {
            $label = $this->catalog->customVocabLabel((int) $m[1]);
            if ($label === null) {
                $this->warnings[] = "{$this->subject}: custom vocabulary {$m[1]} of \"{$key}\" not found; exported with its id";
            } else {
                $clean['type'] = "customvocab:{$label}";
            }
        }

        if (isset($clean['@annotation']) && is_array($clean['@annotation'])) {
            $annotation = [];
            foreach ($clean['@annotation'] as $annotationKey => $values) {
                if (is_string($annotationKey) && PortableJsonLd::splitTerm($annotationKey) !== null && PortableJsonLd::isValueList($values)) {
                    $annotation[$annotationKey] = $this->values($values, $annotationKey);
                }
            }
            $clean['@annotation'] = $annotation;
        }
        return $clean;
    }

    private function itemSets(mixed $itemSets): array
    {
        $out = [];
        foreach (is_array($itemSets) ? $itemSets : [] as $itemSet) {
            $id = $this->id($itemSet);
            if ($id === null) {
                continue;
            }
            $match = $this->catalog->refValue('item_sets', $id, $this->referenceBy);
            if ($match === null) {
                $this->warnings[] = "{$this->subject}: the link \"o:item_set\" to item set {$id} has no {$this->referenceBy} value and is not exported";
                continue;
            }
            $out[] = [PortableJsonLd::PREFIX . ':' . PortableJsonLd::REF => [$this->referenceBy => $match]];
        }
        return $out;
    }

    private function sites(mixed $sites): array
    {
        $out = [];
        foreach (is_array($sites) ? $sites : [] as $site) {
            $id = $this->id($site);
            $slug = $id === null ? null : $this->catalog->siteSlug($id);
            if ($slug !== null) {
                $out[] = ['o:slug' => $slug];
            }
        }
        return $out;
    }

    /** The inline context: o, o-ref and the namespace of every prefix the document uses. */
    private function context(array $doc, ?string $classTerm): array
    {
        $prefixes = [];
        $types = isset($doc['@type']) ? (array) $doc['@type'] : [];
        foreach ([...array_keys($doc), ...$types, $classTerm] as $term) {
            $parts = is_string($term) ? PortableJsonLd::splitTerm($term) : null;
            if ($parts !== null && $parts[0] !== PortableJsonLd::PREFIX) {
                $prefixes[$parts[0]] = true;
            }
        }
        $context = ['o' => PortableJsonLd::O_NS, PortableJsonLd::PREFIX => PortableJsonLd::NS];
        foreach (array_keys($prefixes) as $prefix) {
            $namespace = $this->catalog->namespaceForPrefix($prefix);
            if ($namespace !== null) {
                $context[$prefix] = $namespace;
            }
        }
        ksort($context);
        return $context;
    }

    /** The id of a reference ({"o:id": n} or a bare number). */
    private function id(mixed $reference): ?int
    {
        $id = is_array($reference) ? ($reference['o:id'] ?? null) : $reference;
        return is_numeric($id) ? (int) $id : null;
    }

    private function firstValue(mixed $values): ?string
    {
        $first = is_array($values) ? ($values[0] ?? null) : null;
        $value = is_array($first) ? ($first['@value'] ?? $first['@id'] ?? null) : null;
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
