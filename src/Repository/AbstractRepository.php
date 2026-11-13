<?php
namespace OSC\Repository;

use OSC\Cache;
use OSC\Cache\CacheInterface;
use Throwable;

/**
 * @template T
 */
abstract class AbstractRepository implements RepositoryInterface
{
    private CacheInterface $cache;
    private ?string $cacheKey = null;

    /** Why entries() failed in this process: rethrown instead of fetching an unreachable repository again. */
    private ?Throwable $failure = null;

    public function __construct()
    {
        $this->cache = Cache::getCache();
    }

    abstract protected function entries(): array;

    /**
     * @throws Throwable The fetch failure, also on later calls in the same process
     */
    public function list(): array
    {
        // an unreachable repository is tried once per run: on a blackholed network every attempt
        // waits for the timeouts
        if ($this->failure) {
            throw $this->failure;
        }

        $cacheKey = $this->getCacheKey();
        $entries = $this->cache->get($cacheKey);

        if ( !$entries ) {
            try {
                $entries = $this->entries();
            } catch (Throwable $e) {
                $this->failure = $e;
                throw $e;
            }
            $this->cache->set($cacheKey, $entries);
        }
        return $entries;
    }

    /**
     * Generate a PSR-16 compliant cache key based on the full class name.
     * Caches the result for performance.
     *
     * PSR-16 reserves characters: {}()/\@:
     *
     * @return string Safe cache key
     */
    protected function getCacheKey(): string
    {
        if ($this->cacheKey === null) {
            // Get full class name with namespace
            $className = static::class;

            // Remove PSR-16 reserved characters: {}()/\@:
            // Replace with underscores for readability
            $this->cacheKey = str_replace(
                ['\\', '/', '{', '}', '(', ')', '@', ':'],
                '_',
                $className
            );
        }

        return $this->cacheKey;
    }

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

    public function refresh(): void
    {
        $this->failure = null;
        $cacheKey = $this->getCacheKey();
        $this->cache->delete($cacheKey);
    }
}
