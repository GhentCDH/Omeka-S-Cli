<?php
namespace OSC\Repository;

/**
 * For items that can be resolved by multiple types of identifiers
 */
interface ResolvableInterface
{
    /**
     * Resolve by a specific identifier
     *
     * @param string $identifier The identifier value
     * @param string|null $type The identifier type (e.g., 'uri', 'prefix', 'iri')
     * @return bool True if this item matches the identifier
     */
    public function resolves(string $identifier, ?string $type = null): bool;

    /**
     * Get all identifiers for this item
     *
     * @return array<string, string> Map of identifier types to values
     */
    public function getIdentifiers(): array;
}