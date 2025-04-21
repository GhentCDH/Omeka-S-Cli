<?php
namespace OSC\Repository;

/**
 * @template T
 */
abstract class AbstractRepository implements RepositoryInterface
{
    abstract public function list(): array;

    /**
     * @param string $id
     * @return T|null
     */
    public function find(string $id): ?object
    {
        $id = strtolower($id);
        return $this->list()[$id] ?? null;
    }

    /**
     * @param string $query
     * @return T[]
     */
    public function search(string $query): array
    {
        $query = strtolower($query);
        /** @var MatchableInterface $item */
        return array_filter($this->list(), function ($item) use ($query) {
            return $item->matches($query);
        });
    }
}
