<?php
namespace OSC\Resource;

/**
 * The outcome of resolving one o-ref:ref (see RefResolver): the id of the one record it names, or why
 * there is none. The caller decides what a failure means (a warning, or an error).
 */
final class RefResolution
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const NOT_FOUND = 'not_found';
    public const AMBIGUOUS = 'ambiguous';

    /** API resource name => wording in messages. */
    private const RESOURCE_WORDS = ['items' => 'items', 'item_sets' => 'item sets', 'media' => 'media'];

    private function __construct(
        public readonly string $status,
        public readonly string $resourceName,
        public readonly ?int $id = null,
        public readonly string $term = '',
        public readonly string $value = '',
        public readonly int $matches = 0,
    ) {
    }

    public static function invalid(string $resourceName): self
    {
        return new self(self::INVALID, $resourceName);
    }

    /** @param int[] $ids The records that have $value for $term */
    public static function fromMatches(string $resourceName, string $term, string $value, array $ids): self
    {
        return match (count($ids)) {
            0 => new self(self::NOT_FOUND, $resourceName, null, $term, $value),
            1 => new self(self::OK, $resourceName, (int) reset($ids), $term, $value, 1),
            default => new self(self::AMBIGUOUS, $resourceName, null, $term, $value, count($ids)),
        };
    }

    public function isResolved(): bool
    {
        return $this->status === self::OK;
    }

    /** The resource name in words, e.g. "item sets". */
    public function words(): string
    {
        return self::RESOURCE_WORDS[$this->resourceName] ?? $this->resourceName;
    }

    /**
     * Why the reference did not resolve, e.g. 'link "o:item_set" to item sets with dcterms:title = "x" not found'.
     *
     * @param string $what The reference in words, e.g. 'link "dcterms:relation"'
     */
    public function describe(string $what): string
    {
        $words = $this->words();
        return match ($this->status) {
            self::INVALID => "{$what} has an invalid reference",
            self::NOT_FOUND => "{$what} to {$words} with {$this->term} = \"{$this->value}\" not found",
            self::AMBIGUOUS => "{$what} to {$words} with {$this->term} = \"{$this->value}\" matches {$this->matches} {$words}",
            default => "{$what} resolves to {$words} {$this->id}",
        };
    }
}
