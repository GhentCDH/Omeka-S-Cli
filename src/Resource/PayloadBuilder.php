<?php
namespace OSC\Resource;

/**
 * Turn a JSON-LD resource (the portable format, or Omeka's own API output) into a create payload for
 * the Omeka API of the target instance.
 *
 * - Properties are matched by namespace: a term's prefix is looked up in the document's @context (else
 *   taken as the target's prefix) and mapped to the prefix the target uses for that namespace.
 * - Every value gets "property_id": "auto" and loses the API's output-only keys.
 * - References (o-ref:ref, class terms, template labels, site slugs) become the target's ids.
 *
 * It checks only what Omeka would crash on (an unknown property, a value without a type) or silently
 * drop (an unknown data type); Omeka validates everything else on create. All problems of a resource
 * are collected and thrown together. A reference that does not resolve is a warning: the value is
 * skipped, the resource is still created.
 */
final class PayloadBuilder
{
    /** Keys that describe the resource on its source instance, or that Omeka ignores on create. */
    private const REMOVED_KEYS = [
        '@context', '@id', '@type', '@reverse', 'o:id', 'o:owner', 'o:created', 'o:modified', 'o:title',
        'o:thumbnail', 'thumbnail_display_urls', 'o:primary_media', 'o:media',
    ];

    /** Output-only keys of a value object (besides thumbnail_*). */
    private const VALUE_OUTPUT_KEYS = ['property_id', 'property_label', 'value_resource_name', 'url', 'display_title'];

    /** Data type prefix => the module that provides it, for the "not available" hint. */
    private const MODULE_HINTS = [
        'numeric' => 'NumericDataTypes',
        'valuesuggest' => 'ValueSuggest',
        'valuesuggestall' => 'ValueSuggest',
        'customvocab' => 'CustomVocab',
        'geometry' => 'DataTypeGeometry',
        'geography' => 'DataTypeGeometry',
    ];

    /** API resource name => wording in messages. */
    private const RESOURCE_WORDS = ['items' => 'items', 'item_sets' => 'item sets', 'media' => 'media'];

    /** @var array<string,string> */
    private array $context = [];
    private string $refKey = '';
    /** @var string[] */
    private array $problems = [];
    /** @var string[] */
    private array $warnings = [];

    public function __construct(private Catalog $catalog)
    {
    }

    /**
     * @param array       $document   One resource; its @context must be an inline map or absent
     * @param string      $label      Prefixes every message (e.g. the file name and title)
     * @param string|null $identifyBy A property (target term) whose value identifies the resource
     * @throws ResourceImportException Listing every problem of the resource
     */
    public function build(array $document, ResourceType $type, string $label, ?string $identifyBy = null): BuildResult
    {
        $this->context = PortableJsonLd::contextMap($document['@context'] ?? null);
        $this->refKey = PortableJsonLd::keyFor($this->context, PortableJsonLd::REF);
        $this->problems = [];
        $this->warnings = [];

        $payload = [];
        foreach ($document as $key => $value) {
            if (!is_string($key) || in_array($key, self::REMOVED_KEYS, true) || $this->isRefVocabulary($key)) {
                continue;
            }
            switch ($key) {
                case 'o:resource_class':
                    $reference = $this->resourceClass($value);
                    if ($reference !== null) {
                        $payload[$key] = $reference;
                    }
                    break;
                case 'o:resource_template':
                    $reference = $this->resourceTemplate($value);
                    if ($reference !== null) {
                        $payload[$key] = $reference;
                    }
                    break;
                case 'o:item_set':
                    if ($type === ResourceType::Item) {
                        $payload[$key] = $this->itemSets($value);
                    }
                    break;
                case 'o:site':
                    $sites = $type === ResourceType::Item ? $this->sites($value) : null;
                    if ($sites !== null) {
                        $payload[$key] = $sites;
                    }
                    break;
                default:
                    if (PortableJsonLd::splitTerm($key) !== null && PortableJsonLd::isValueList($value)) {
                        if ($this->isModuleData($key, $value)) {
                            $this->warnings[] = "\"{$key}\" is module data and is not imported";
                            break;
                        }
                        [$term, $values] = $this->property($key, $value, '');
                        if ($term !== null && $values) {
                            $payload[$term] = $values;
                        }
                    } else {
                        // o:is_public, o:is_open, module data, ...
                        $payload[$key] = $value;
                    }
            }
        }

        $title = $this->title($document, $payload);
        $identity = $this->identity($document, $payload, $identifyBy, $title);
        if ($identity === null && !$this->problems) {
            $this->warnings[] = "the {$type->label()} has no o-ref:id and no title, so it cannot be matched: it is created on every import";
        }
        if ($this->problems) {
            throw new ResourceImportException($label, $this->problems);
        }
        return new BuildResult($payload, array_map(fn($w) => "{$label}: {$w}", $this->warnings), $identity, $title);
    }

    /**
     * A property's values, under the target's term.
     *
     * @param string $where Prefix for messages (e.g. 'annotation of "dcterms:title": ')
     * @return array{0:?string,1:array}
     */
    private function property(string $key, array $values, string $where): array
    {
        [$term, $reason, $namespace] = $this->mapTerm($key);
        if ($term === null) {
            $this->problems[] = "{$where}property \"{$key}\"{$reason}";
            return [null, []];
        }
        if (!$this->catalog->hasProperty($term)) {
            $this->problems[] = "{$where}property \"{$key}\" does not exist in vocabulary {$namespace}";
            return [null, []];
        }
        $out = [];
        foreach ($values as $value) {
            $value = $this->value($value, $key, $where);
            if ($value !== null) {
                $out[] = $value;
            }
        }
        return [$term, $out];
    }

    /** One value ready for the API, or null when it is skipped (a problem or a warning is recorded). */
    private function value(array $value, string $key, string $where): ?array
    {
        if (!isset($value['type']) || !is_string($value['type']) || $value['type'] === '') {
            $this->problems[] = "{$where}property \"{$key}\": a value has no \"type\"";
            return null;
        }
        $type = $value['type'];
        if (str_starts_with($type, 'customvocab:') && !ctype_digit(substr($type, 12))) {
            $vocab = substr($type, 12);
            $id = $this->catalog->customVocabId($vocab);
            if ($id === null) {
                $this->problems[] = "{$where}property \"{$key}\": custom vocabulary \"{$vocab}\" not found";
                return null;
            }
            $type = "customvocab:{$id}";
        }
        if (!$this->catalog->hasDataType($type)) {
            $module = self::MODULE_HINTS[explode(':', $type)[0]] ?? null;
            $hint = $module ? " (install the {$module} module)" : '';
            $this->problems[] = "{$where}property \"{$key}\": data type \"{$type}\" is not available{$hint}";
            return null;
        }
        $value['type'] = $type;

        if (isset($value[$this->refKey])) {
            $id = $this->resolveRef($value[$this->refKey], $this->resourceName($type, $value), "link \"{$key}\"");
            if ($id === null) {
                return null;
            }
            unset($value[$this->refKey]);
            $value['value_resource_id'] = $id;
        }

        foreach (array_keys($value) as $valueKey) {
            if (in_array($valueKey, self::VALUE_OUTPUT_KEYS, true) || (is_string($valueKey) && str_starts_with($valueKey, 'thumbnail_'))) {
                unset($value[$valueKey]);
            }
        }
        if (isset($value['value_resource_id'])) {
            // a resource value's @id is the linked resource's API URL on the source instance
            unset($value['@id']);
        }
        if (isset($value['@annotation']) && is_array($value['@annotation'])) {
            $value['@annotation'] = $this->annotation($value['@annotation'], $key);
        }
        $value['property_id'] = 'auto';
        return $value;
    }

    /** A value annotation: a map of properties to values, processed like the resource's own. */
    private function annotation(array $annotation, string $key): array
    {
        $out = [];
        foreach ($annotation as $annotationKey => $values) {
            if (is_string($annotationKey) && PortableJsonLd::splitTerm($annotationKey) !== null && PortableJsonLd::isValueList($values)) {
                [$term, $values] = $this->property($annotationKey, $values, "annotation of \"{$key}\": ");
                if ($term !== null && $values) {
                    $out[$term] = $values;
                }
            } else {
                $out[$annotationKey] = $values;
            }
        }
        return $out;
    }

    /** The API resource name a linked value points to. */
    private function resourceName(string $type, array $value): string
    {
        return match ($type) {
            'resource:item' => 'items',
            'resource:itemset' => 'item_sets',
            'resource:media' => 'media',
            default => is_string($value['value_resource_name'] ?? null) ? $value['value_resource_name'] : 'items',
        };
    }

    /**
     * The id of the one resource an o-ref:ref names, or null with a warning.
     *
     * @param string $what For the warning, e.g. 'link "dcterms:relation"'
     */
    private function resolveRef(mixed $ref, string $resourceName, string $what): ?int
    {
        if (!is_array($ref) || count($ref) !== 1 || !is_string(array_key_first($ref)) || !is_scalar(reset($ref))) {
            $this->warnings[] = "{$what} has an invalid reference; the value is skipped";
            return null;
        }
        $term = (string) array_key_first($ref);
        $match = (string) reset($ref);
        $ids = $this->catalog->findByValue($resourceName, $this->mapTerm($term)[0] ?? $term, $match);
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        $words = self::RESOURCE_WORDS[$resourceName] ?? $resourceName;
        $this->warnings[] = $ids
            ? "{$what} to {$words} with {$term} = \"{$match}\" matches " . count($ids) . " {$words}; the value is skipped"
            : "{$what} to {$words} with {$term} = \"{$match}\" not found; the value is skipped";
        return null;
    }

    private function resourceClass(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['o:term']) && is_string($value['o:term'])) {
            $term = $value['o:term'];
            [$mapped, $reason] = $this->mapTerm($term);
            if ($mapped === null) {
                $this->problems[] = "resource class \"{$term}\"{$reason}";
                return null;
            }
            $id = $this->catalog->resourceClassId($mapped);
            if ($id === null) {
                $this->problems[] = "resource class \"{$term}\" not found";
                return null;
            }
            return ['o:id' => $id];
        }
        return isset($value['o:id']) && is_numeric($value['o:id']) ? ['o:id' => (int) $value['o:id']] : null;
    }

    private function resourceTemplate(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (isset($value['o:label']) && is_string($value['o:label'])) {
            $id = $this->catalog->resourceTemplateId($value['o:label']);
            if ($id === null) {
                $this->problems[] = "resource template \"{$value['o:label']}\" not found (resource-template:import)";
                return null;
            }
            return ['o:id' => $id];
        }
        return isset($value['o:id']) && is_numeric($value['o:id']) ? ['o:id' => (int) $value['o:id']] : null;
    }

    /** @return array<int,array{"o:id":int}> */
    private function itemSets(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            $id = null;
            if (is_array($entry) && isset($entry[$this->refKey])) {
                $id = $this->resolveRef($entry[$this->refKey], 'item_sets', 'link "o:item_set"');
            } elseif (is_array($entry) && isset($entry['o:id']) && is_numeric($entry['o:id'])) {
                $id = (int) $entry['o:id'];
            } elseif (is_numeric($entry)) {
                $id = (int) $entry;
            }
            if ($id !== null) {
                $out[] = ['o:id' => $id];
            }
        }
        return $out;
    }

    /**
     * Sites by slug or title (raw ids pass). When sites were named but none exists, the default site,
     * if one is set; else null, so Omeka assigns the item to the sites that take new items.
     *
     * @return array<int,array{"o:id":int}>|null
     */
    private function sites(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        $named = 0;
        foreach ($value as $entry) {
            if (is_array($entry) && isset($entry['o:slug']) && is_string($entry['o:slug'])) {
                $named++;
                $id = $this->catalog->siteId($entry['o:slug']);
                if ($id === null) {
                    $this->warnings[] = "site \"{$entry['o:slug']}\" not found; the item is not added to it";
                    continue;
                }
                $out[] = ['o:id' => $id];
            } elseif (is_array($entry) && isset($entry['o:id']) && is_numeric($entry['o:id'])) {
                $out[] = ['o:id' => (int) $entry['o:id']];
            } elseif (is_numeric($entry)) {
                $out[] = ['o:id' => (int) $entry];
            }
        }
        if (!$out && $named > 0) {
            $default = $this->catalog->defaultSiteId();
            return $default === null ? null : [['o:id' => $default]];
        }
        return $out;
    }

    private function title(array $document, array $payload): ?string
    {
        $title = $document['o:title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            return $title;
        }
        return $this->firstValue($payload['dcterms:title'] ?? []);
    }

    private function identity(array $document, array $payload, ?string $identifyBy, ?string $title): ?Identity
    {
        if ($identifyBy !== null) {
            $value = $this->firstValue($payload[$identifyBy] ?? []);
            if ($value === null) {
                $this->problems[] = "--identify-by {$identifyBy}: the resource has no value for it";
                return null;
            }
            return new Identity($identifyBy, $value);
        }
        $id = $document[PortableJsonLd::keyFor($this->context, PortableJsonLd::ID)] ?? null;
        if (is_array($id) && count($id) === 1 && is_string(array_key_first($id)) && is_scalar(reset($id))) {
            $term = (string) array_key_first($id);
            return new Identity($this->mapTerm($term)[0] ?? $term, (string) reset($id));
        }
        return $title !== null ? new Identity(null, $title) : null;
    }

    /** The first literal value or URI of a value list. */
    private function firstValue(mixed $values): ?string
    {
        $first = is_array($values) ? ($values[0] ?? null) : null;
        $value = is_array($first) ? ($first['@value'] ?? $first['@id'] ?? null) : null;
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * A document term mapped to the target's term for the same namespace.
     *
     * @return array{0:?string,1:string,2:?string} [target term or null, the reason when null, namespace]
     */
    private function mapTerm(string $term): array
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

    /**
     * Whether a key that looks like a property is a module's data instead (e.g. Mapping's
     * "o-module-mapping:marker": references with an @id, no typed values), from no known vocabulary.
     */
    private function isModuleData(string $key, array $values): bool
    {
        foreach ($values as $value) {
            if (isset($value['type']) || isset($value['@value']) || isset($value[$this->refKey])) {
                return false;
            }
        }
        return $this->mapTerm($key)[0] === null;
    }

    /** Whether a key belongs to the o-ref vocabulary (matched by namespace). */
    private function isRefVocabulary(string $key): bool
    {
        $prefix = explode(':', $key, 2)[0];
        $namespace = $this->context[$prefix] ?? ($prefix === PortableJsonLd::PREFIX ? PortableJsonLd::NS : null);
        return str_contains($key, ':') && $namespace === PortableJsonLd::NS;
    }
}
