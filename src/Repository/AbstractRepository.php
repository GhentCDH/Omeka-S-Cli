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
     * @param string|null $type
     * @return T|null
     */
    public function find(string $id, ?string $type = null): ?object
    {
        $ret = array_filter($this->list(), function ($item) use ($id, $type) {
            return $item->resolves($id, $type);
        });
        if ($ret && count($ret)) {
            return array_shift($ret);
        }
        return null;
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
