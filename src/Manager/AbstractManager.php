<?php
namespace OSC\Manager;

use OSC\Exceptions\NotFoundException;
use OSC\Repository\RepositoryInterface;

/**
 * @template T
 */
abstract class AbstractManager
{
    /** @var RepositoryInterface<T>[] $repositories */
    private array $repositories = [];

    private static array $instances = [];

    public static function getInstance(): static
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }
        return self::$instances[$cls];
    }

    /**
     * @param RepositoryInterface<T> $repository
     */
    public function addRepository(RepositoryInterface $repository): void
    {
        $this->repositories[$repository->getId()] = $repository;
    }

    /**
     * @return RepositoryInterface<T>
     */
    public function getRepository(string $id): RepositoryInterface
    {
        $repository = $this->repositories[$id] ?? null;
        if (!$repository) {
            throw new NotFoundException("Repository '$id' not found");
        }
        return $repository;
    }

    /**
     * @return RepositoryInterface<T>[]
     */
    public function repositories(): array
    {
        return $this->repositories;
    }

    /**
     * @param string $id
     * @param string|null $version
     * @return Result<T>|null
     */
    public function find(string $id, ?string $version = null): ?Result
    {
        foreach($this->repositories as $repository) {
            $item = $repository->find($id);
            if ($item) {
                // todo: implement semantic version comparison
                $versionNumber = $version ?
                    $item->getVersion($version)?->getVersionNumber() :
                    $item->getLatestVersionNumber();
                return new Result($item, $repository, $versionNumber);
            }
        }
        return null;
    }

    /**
     * @return Result<T>[]
     */
    public function list(?string $repositoryId = null): array
    {
        if ($repositoryId) {
            if (!isset($this->repositories()[$repositoryId])) {
                throw new NotFoundException("Repository '$repositoryId' not found");
            }
            $repositories = [$this->repositories()[$repositoryId]];
        } else {
            $repositories = $this->repositories;
        }

        $result = [];
        foreach ($repositories as $repository) {
            $items = $repository->list();
            foreach ($items as $item) {
                $key = strtolower($item->getId());
                if (!isset($result[$key])) {
                    $result[$key] = new Result($item, $repository, $item->getLatestVersion()->getVersionNumber());
                }
            }
        }
        ksort($result, SORT_NATURAL);
        return $result ? array_values($result) : [];
    }

    /**
     * @return Result<T>[]
     */
    public function search(string $query, ?string $repositoryId = null): array
    {
        if ($repositoryId) {
            if (!isset($this->repositories()[$repositoryId])) {
                throw new NotFoundException("Repository not found: $repositoryId");
            }
            $repositories = [$this->repositories()[$repositoryId]];
        } else {
            $repositories = $this->repositories;
        }

        $ret = [];
        foreach ($repositories as $repository) {
            $items = $repository->search($query);
            foreach ($items as $item) {
                $key = strtolower($item->getId());
                if (!isset($ret[$key])) {
                    $ret[$key] = new Result($item, $repository, $item->getLatestVersion()->getVersionNumber());
                }
            }
        }
        ksort($ret, SORT_NATURAL);
        return $ret ? array_values($ret) : [];
    }
}