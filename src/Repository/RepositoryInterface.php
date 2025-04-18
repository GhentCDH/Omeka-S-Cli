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
     * @return T|null
     */
    public function find(string $id): ?object;

    /**
     * @return T[]
     */
    public function search(string $query): array;
}