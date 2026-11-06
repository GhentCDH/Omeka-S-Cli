<?php
namespace OSC\Resource;

/**
 * What the import/export core needs to know about an Omeka S instance. The core (PayloadBuilder,
 * Exporter, QueryBuilder) talks to the instance only through this interface, so it can be tested
 * without Omeka. Resource names are API names: "items", "item_sets", "media".
 */
interface Catalog
{
    /** The namespace URI of the installed vocabulary with this prefix. */
    public function namespaceForPrefix(string $prefix): ?string;

    /** The prefix of the installed vocabulary with this namespace URI. */
    public function prefixForNamespace(string $namespaceUri): ?string;

    public function hasProperty(string $term): bool;

    /** Whether a data type (e.g. "literal", "numeric:timestamp", "customvocab:3") is registered. */
    public function hasDataType(string $name): bool;

    public function resourceClassId(string $term): ?int;

    public function resourceClassTerm(int $id): ?string;

    public function resourceTemplateId(string $label): ?int;

    public function resourceTemplateLabel(int $id): ?string;

    /** A site by slug or title, case-insensitive. */
    public function siteId(string $slugOrTitle): ?int;

    public function siteSlug(int $id): ?string;

    /** The site set as the installation's default site, if any. */
    public function defaultSiteId(): ?int;

    public function customVocabId(string $label): ?int;

    public function customVocabLabel(int $id): ?string;

    /**
     * Ids of the resources having $value for property $term (exact match).
     *
     * @return int[]
     */
    public function findByValue(string $resourceName, string $term, string $value): array;

    /**
     * Ids of the resources whose display title is $title (exact match).
     *
     * @return int[]
     */
    public function findByTitle(string $resourceName, string $title): array;

    /** The first value (literal value or URI) of property $term on a resource, or null. */
    public function refValue(string $resourceName, int $id, string $term): ?string;
}
