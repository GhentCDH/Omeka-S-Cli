<?php

namespace Tests\Resource;

use OSC\Resource\Catalog;

/**
 * An in-memory Catalog for the import/export tests: set the public arrays, then pass it in.
 */
class FakeCatalog implements Catalog
{
    /** @var array<string,string> prefix => namespace */
    public array $vocabularies = [];
    /** @var string[] terms */
    public array $properties = [];
    /** @var string[] data type names */
    public array $dataTypes = [];
    /** @var array<int,string> id => term */
    public array $classes = [];
    /** @var array<int,string> id => label */
    public array $templates = [];
    /** @var array<int,array{0:string,1:string}> id => [slug, title] */
    public array $sites = [];
    public ?int $defaultSite = null;
    /** @var array<int,string> id => label */
    public array $customVocabs = [];
    /** @var array<string,array<int,array<string,string>>> resource name => id => term => value */
    public array $values = [];
    /** @var array<string,array<int,string>> resource name => id => title */
    public array $titles = [];

    /** The catalog the import/export tests share: Dublin Core, a class, a template, a site, a few records. */
    public static function sample(): FakeCatalog
    {
        $catalog = new self();
        $catalog->vocabularies = [
            'dcterms' => 'http://purl.org/dc/terms/',
            'dctype' => 'http://purl.org/dc/dcmitype/',
            'schema' => 'https://schema.org/',
        ];
        $catalog->properties = ['dcterms:title', 'dcterms:description', 'dcterms:relation', 'dcterms:identifier', 'dcterms:date', 'dcterms:subject', 'schema:name'];
        $catalog->dataTypes = ['literal', 'uri', 'resource', 'resource:item', 'resource:itemset', 'customvocab:7'];
        $catalog->classes = [5 => 'dctype:StillImage'];
        $catalog->templates = [3 => 'Artwork'];
        $catalog->sites = [1 => ['collections', 'Collections']];
        $catalog->customVocabs = [7 => 'Colours'];
        $catalog->values = [
            'items' => [42 => ['dcterms:identifier' => 'obj-42', 'dcterms:title' => 'Lady with an Ermine']],
            'item_sets' => [9 => ['dcterms:title' => 'Paintings', 'dcterms:identifier' => 'set-1']],
        ];
        $catalog->titles = ['items' => [42 => 'Lady with an Ermine']];
        return $catalog;
    }

    public function namespaceForPrefix(string $prefix): ?string
    {
        return $this->vocabularies[$prefix] ?? null;
    }

    public function prefixForNamespace(string $namespaceUri): ?string
    {
        $prefix = array_search($namespaceUri, $this->vocabularies, true);
        return $prefix === false ? null : $prefix;
    }

    public function hasProperty(string $term): bool
    {
        return in_array($term, $this->properties, true);
    }

    public function hasDataType(string $name): bool
    {
        return in_array($name, $this->dataTypes, true);
    }

    public function resourceClassId(string $term): ?int
    {
        $id = array_search($term, $this->classes, true);
        return $id === false ? null : $id;
    }

    public function resourceClassTerm(int $id): ?string
    {
        return $this->classes[$id] ?? null;
    }

    public function resourceTemplateId(string $label): ?int
    {
        $id = array_search($label, $this->templates, true);
        return $id === false ? null : $id;
    }

    public function resourceTemplateLabel(int $id): ?string
    {
        return $this->templates[$id] ?? null;
    }

    public function siteId(string $slugOrTitle): ?int
    {
        foreach ($this->sites as $id => [$slug, $title]) {
            if (strcasecmp($slug, $slugOrTitle) === 0 || strcasecmp($title, $slugOrTitle) === 0) {
                return $id;
            }
        }
        return null;
    }

    public function siteSlug(int $id): ?string
    {
        return $this->sites[$id][0] ?? null;
    }

    public function defaultSiteId(): ?int
    {
        return $this->defaultSite;
    }

    public function customVocabId(string $label): ?int
    {
        $id = array_search($label, $this->customVocabs, true);
        return $id === false ? null : $id;
    }

    public function customVocabLabel(int $id): ?string
    {
        return $this->customVocabs[$id] ?? null;
    }

    public function findByValue(string $resourceName, string $term, string $value): array
    {
        $ids = [];
        foreach ($this->values[$resourceName] ?? [] as $id => $values) {
            if (($values[$term] ?? null) === $value) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function findByTitle(string $resourceName, string $title): array
    {
        return array_keys(array_filter($this->titles[$resourceName] ?? [], fn($t) => $t === $title));
    }

    public function refValue(string $resourceName, int $id, string $term): ?string
    {
        return $this->values[$resourceName][$id][$term] ?? null;
    }
}
