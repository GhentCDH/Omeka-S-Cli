<?php
namespace OSC\Manager;

use OSC\Exceptions\NotFoundException;
use OSC\Repository\RepositoryInterface;
use Throwable;

/**
 * @template T
 */
abstract class AbstractManager
{
    /** @var RepositoryInterface<T>[] $repositories */
    private array $repositories = [];

    private static array $instances = [];

    /**
     * Warnings collected while querying repositories, shared by all managers.
     *
     * @var string[]
     */
    private static array $warnings = [];

    public static function getInstance(): static
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
            self::$instances[$cls]->registerRepositories();
        }
        return self::$instances[$cls];
    }

    protected abstract function registerRepositories(): void;

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
     * Query a repository, tolerating repository level failures.
     *
     * A repository that can not be reached (network error, rate limiting, ...) should not
     * break commands that aggregate several repositories: the failure is collected as a
     * warning and the other repositories are still used.
     *
     * @template R
     * @param RepositoryInterface<T> $repository
     * @param callable(RepositoryInterface<T>): R $query
     * @param R $default Value to return when the repository fails
     * @return R
     */
    private function query(RepositoryInterface $repository, callable $query, mixed $default): mixed
    {
        try {
            return $query($repository);
        } catch (Throwable $e) {
            self::addWarning(sprintf(
                "Repository '%s' is unavailable: %s",
                $repository->getDisplayName(),
                $e->getMessage()
            ));
            return $default;
        }
    }

    private static function addWarning(string $warning): void
    {
        if (!in_array($warning, self::$warnings, true)) {
            self::$warnings[] = $warning;
        }
    }

    /**
     * Return the warnings collected so far and clear them.
     *
     * @return string[]
     */
    public static function takeWarnings(): array
    {
        $warnings = self::$warnings;
        self::$warnings = [];
        return $warnings;
    }

    /**
     * Refresh all repositories.
     */
    public function refreshRepositories(): void
    {
        foreach ($this->repositories as $repository) {
            $repository->refresh();
        }
    }

    /**
     * @param string $id
     * @param string|null $type
     * @return Result<T>|null
     */
    public function find(string $id, ?string $type = null, ?string $repositoryId = null): ?Result
    {
        foreach($this->repositories as $repository) {
            if ($repositoryId && $repository->getId() !== $repositoryId) {
                continue;
            }
            // an explicitly requested repository must fail loudly
            $item = $repositoryId
                ? $repository->find($id, $type)
                : $this->query($repository, fn($r) => $r->find($id, $type), null);
            if ($item) {
                return new Result($item, $repository);
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
                return []; // Repository not found, return empty array
            }
            $repositories = [$this->repositories()[$repositoryId]];
        } else {
            $repositories = $this->repositories;
        }

        $result = [];
        foreach ($repositories as $repository) {
            // an explicitly requested repository must fail loudly
            $items = $repositoryId
                ? $repository->list()
                : $this->query($repository, fn($r) => $r->list(), []);
            foreach ($items as $item) {
                $key = strtolower($item->getId());
                if (!isset($result[$key])) {
                    $result[$key] = new Result($item, $repository);
                }
            }
        }
        ksort($result, SORT_NATURAL);
        return $result ? array_values($result) : [];
    }

    /**
     * List items from all repositories except $excludeRepositoryId,
     * keeping only those whose ID is not present in $excludeRepositoryId.
     *
     * @return Result<T>[]
     */
    public function listExclusive(string $excludeRepositoryId): array
    {
        $excludeIds = [];
        $excludeRepository = $this->repositories[$excludeRepositoryId] ?? null;
        if ($excludeRepository) {
            foreach ($this->query($excludeRepository, fn($r) => $r->list(), []) as $item) {
                $excludeIds[strtolower($item->getId())] = true;
            }
        }

        $result = [];
        foreach ($this->repositories as $id => $repository) {
            if ($id === $excludeRepositoryId) {
                continue;
            }
            foreach ($this->query($repository, fn($r) => $r->list(), []) as $item) {
                $key = strtolower($item->getId());
                if (!isset($excludeIds[$key]) && !isset($result[$key])) {
                    $result[$key] = new Result($item, $repository);
                }
            }
        }
        ksort($result, SORT_NATURAL);
        return $result ? array_values($result) : [];
    }

    /**
     * Search items from all repositories except $excludeRepositoryId,
     * keeping only those whose ID is not present in $excludeRepositoryId.
     *
     * @return Result<T>[]
     */
    public function searchExclusive(string $query, string $excludeRepositoryId): array
    {
        $excludeIds = [];
        $excludeRepository = $this->repositories[$excludeRepositoryId] ?? null;
        if ($excludeRepository) {
            foreach ($this->query($excludeRepository, fn($r) => $r->list(), []) as $item) {
                $excludeIds[strtolower($item->getId())] = true;
            }
        }

        $result = [];
        foreach ($this->repositories as $id => $repository) {
            if ($id === $excludeRepositoryId) {
                continue;
            }
            foreach ($this->query($repository, fn($r) => $r->search($query), []) as $item) {
                $key = strtolower($item->getId());
                if (!isset($excludeIds[$key]) && !isset($result[$key])) {
                    $result[$key] = new Result($item, $repository);
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
                return []; // Repository not found, return empty array
            }
            $repositories = [$this->repositories()[$repositoryId]];
        } else {
            $repositories = $this->repositories();
        }

        $ret = [];
        foreach ($repositories as $repository) {
            // an explicitly requested repository must fail loudly
            $items = $repositoryId
                ? $repository->search($query)
                : $this->query($repository, fn($r) => $r->search($query), []);
            foreach ($items as $item) {
                $key = strtolower($item->getId());
                if (!isset($ret[$key])) {
                    $ret[$key] = new Result($item, $repository);
                }
            }
        }
        ksort($ret, SORT_NATURAL);
        return $ret ? array_values($ret) : [];
    }
}