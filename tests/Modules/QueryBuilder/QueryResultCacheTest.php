<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Modules\QueryBuilder\QueryResultCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class QueryResultCacheSpyCacheItem implements CacheItemInterface {
    private mixed $value = null;

    private bool $isHit = false;

    public function __construct(private string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        return $this;
    }
}

class QueryResultCacheSpyPool implements CacheItemPoolInterface {
    /** @var array<string, QueryResultCacheSpyCacheItem> */
    private array $items = [];

    public int $saveCallCount = 0;

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new QueryResultCacheSpyCacheItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]) && $this->items[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->saveCallCount++;
        $this->items[$item->getKey()] = $item;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

class QueryResultCacheTest extends TestCase {
    /**
     * Mutant: MethodCallRemoval on `$this->cachePool->save($cacheItem)` in set()
     * (QueryResultCache.php:96). Without persisting the item back to the pool, a subsequent
     * get() against a cache implementation that doesn't track hits purely via in-memory
     * object identity would never see the stored value.
     */
    public function testSetPersistsItemToCachePool(): void
    {
        $pool = new QueryResultCacheSpyPool();
        $cache = new QueryResultCache($pool);
        $cache->enable(3600);

        $cache->set('key1', [['id' => 1]]);

        $this->assertSame(1, $pool->saveCallCount);
        $this->assertTrue($pool->hasItem('key1'));
        $this->assertSame([['id' => 1]], $cache->get('key1'));
    }

    /**
     * Mutant: ArrayItemRemoval on 'sql' => $sql in generateCacheKey()
     * (QueryResultCache.php:112..113). Dropping 'sql' from the cache-key payload would make
     * two structurally-different queries (different SQL text) that happen to share every
     * other parameter collide on the same cache key.
     */
    public function testGenerateCacheKeyDiffersWhenSqlDiffers(): void
    {
        $cache = new QueryResultCache(new QueryResultCacheSpyPool());

        $key1 = $cache->generateCacheKey(null, 'SELECT 1', [], false, null, null, [], [], []);
        $key2 = $cache->generateCacheKey(null, 'SELECT 2', [], false, null, null, [], [], []);

        $this->assertNotSame($key1, $key2);
    }

    /**
     * Mutant: ConcatOperandRemoval/Concat mutants on
     * `'g' . $this->generation . '_' . hash(...)` (QueryResultCache.php:124). Pins the exact
     * literal prefix format so dropping/reordering/duplicating the 'g'/'_' segments is caught.
     */
    public function testGenerateCacheKeyHasExactGenerationPrefixFormat(): void
    {
        $cache = new QueryResultCache(new QueryResultCacheSpyPool());
        $cache->setGeneration(3);

        $key = $cache->generateCacheKey(null, 'SELECT 1', [], false, null, null, [], [], []);

        $this->assertMatchesRegularExpression('/^g3_[0-9a-f]{64}$/', $key);
    }

    /**
     * Mutant: UnwrapArrayMap on normalizeParamsForCacheKey() — the per-value normalization
     * closure (object → identity string) must actually be applied element-by-element so two
     * different object instances produce different cache keys despite the raw array containing
     * "the same shaped" unserializable object.
     */
    public function testGenerateCacheKeyNormalizesObjectParamsByIdentity(): void
    {
        $cache = new QueryResultCache(new QueryResultCacheSpyPool());

        $objectA = new \stdClass();
        $objectB = new \stdClass();

        $keyA = $cache->generateCacheKey(null, 'SELECT 1', [$objectA], false, null, null, [], [], []);
        $keyB = $cache->generateCacheKey(null, 'SELECT 1', [$objectB], false, null, null, [], [], []);

        $this->assertNotSame($keyA, $keyB, 'Distinct object instances must normalize to distinct identity strings');
    }

    /**
     * Mutant coverage for normalizeParamsForCacheKey()'s recursive array branch — nested
     * array params must be normalized recursively, not treated as an opaque leaf value.
     */
    public function testGenerateCacheKeyNormalizesNestedArrayParamsRecursively(): void
    {
        $cache = new QueryResultCache(new QueryResultCacheSpyPool());

        $objectA = new \stdClass();
        $objectB = new \stdClass();

        $keyA = $cache->generateCacheKey(null, 'SELECT 1', [[$objectA]], false, null, null, [], [], []);
        $keyB = $cache->generateCacheKey(null, 'SELECT 1', [[$objectB]], false, null, null, [], [], []);

        $this->assertNotSame($keyA, $keyB);
    }
}
