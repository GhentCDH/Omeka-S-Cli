<?php
namespace OSC\Repository;

/**
 * @template T
 */
interface RepositoryInterface extends IdentifiableInterface
{
    public function getDisplayName(): string;

    /**
     * @return T[]
     */
    public function list(): array;

    /**
     * @param string $id
     * @param string|null $type
     * @return T|null
     */
    public function find(string $id, ?string $type = null): ?object;

    /**
     * @return T[]
     */
    public function search(string $query): array;

    public function refresh(): void;
}