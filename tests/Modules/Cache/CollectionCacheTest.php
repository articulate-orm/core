<?php

namespace Articulate\Tests\Modules\Cache;

use Articulate\Modules\Cache\CollectionCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class CacheItem implements CacheItemInterface {
    private mixed $value = null;

    private bool $isHit = false;

    public ?int $expiresAfterSeconds = null;

    public function __construct(private readonly string $key)
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
        $this->expiresAfterSeconds = is_int($time) ? $time : null;

        return $this;
    }
}

class ArrayCachePool implements CacheItemPoolInterface {
    /** @var array<string, CacheItemInterface> */
    public array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new CacheItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return array_combine($keys, array_map($this->getItem(...), $keys));
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

class FailingCachePool extends ArrayCachePool {
    public function getItem(string $key): never
    {
        throw new \RuntimeException('Cache backend failure');
    }

    public function getItems(array $keys = []): iterable
    {
        throw new \RuntimeException('Cache backend failure');
    }
}

/**
 * Unlike ArrayCachePool, getItem() never persists the returned item by
 * itself — only save() does. This is what actually distinguishes "we wrote
 * to the pool" from "we merely mutated a local object", needed to kill the
 * MethodCallRemoval mutant on CollectionCache::setIdList()'s pool->save()
 * call (ArrayCachePool's `??=` already persists on first getItem(), which
 * makes that call look like a no-op to a mutation test).
 */
class SaveOnlyCachePool implements CacheItemPoolInterface {
    /** @var array<string, CacheItemInterface> */
    public array $saved = [];

    public int $saveCallCount = 0;

    public function getItem(string $key): CacheItemInterface
    {
        return $this->saved[$key] ?? new CacheItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return array_combine($keys, array_map($this->getItem(...), $keys));
    }

    public function hasItem(string $key): bool
    {
        return isset($this->saved[$key]) && $this->saved[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->saved = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->saved[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->saved[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->saveCallCount++;
        $this->saved[$item->getKey()] = $item;

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

/**
 * Counts how many getItem()/getItems() calls reach the pool, used to prove
 * the batch existence check does a single getItems() round trip rather than
 * N individual getItem() calls.
 */
class BatchCountingCachePool extends ArrayCachePool {
    public int $getItemCallCount = 0;

    public int $getItemsCallCount = 0;

    public function getItem(string $key): CacheItemInterface
    {
        $this->getItemCallCount++;

        return parent::getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        $this->getItemsCallCount++;

        return array_combine($keys, array_map(fn (string $key) => $this->items[$key] ??= new CacheItem($key), $keys));
    }
}

class CollectionCacheTest extends TestCase {
    public function testGetIdListMissesOnEmptyCache(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $this->assertNull($cache->getIdList('some-key'));
    }

    public function testSetThenGetRoundTripsIdList(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->setIdList('some-key', [1, 2, 3], 60);

        $this->assertSame([1, 2, 3], $cache->getIdList('some-key'));
    }

    public function testDistinctKeysDoNotCollide(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->setIdList('key-a', [1], 60);
        $cache->setIdList('key-b', [2], 60);

        $this->assertSame([1], $cache->getIdList('key-a'));
        $this->assertSame([2], $cache->getIdList('key-b'));
    }

    public function testEvictIdListRemovesOnlyTheTargetedEntry(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->setIdList('key-a', [1], 60);
        $cache->setIdList('key-b', [2], 60);

        $cache->evictIdList('key-a');

        $this->assertNull($cache->getIdList('key-a'));
        $this->assertSame([2], $cache->getIdList('key-b'));
    }

    public function testEvictIdListIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $cache->evictIdList('key-a');
        $this->addToAssertionCount(1);
    }

    public function testGetIdListIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $this->assertNull($cache->getIdList('some-key'));
    }

    public function testSetIdListIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        // Must not throw.
        $cache->setIdList('some-key', [1], 60);
        $this->addToAssertionCount(1);
    }

    public function testSetIdListActuallyPersistsViaPoolSave(): void
    {
        $pool = new SaveOnlyCachePool();
        $cache = new CollectionCache($pool);

        $cache->setIdList('some-key', [1, 2, 3], 60);

        $this->assertSame(1, $pool->saveCallCount, 'setIdList() must call the pool\'s save() to actually persist — a local mutation alone is not a write.');
    }

    public function testGetIdListReadsThroughSaveOnlyPool(): void
    {
        $pool = new SaveOnlyCachePool();
        $cache = new CollectionCache($pool);

        $cache->setIdList('some-key', [1, 2, 3], 60);

        $this->assertSame([1, 2, 3], $cache->getIdList('some-key'));
    }

    public function testKeysAreStoredUnderTheIdListPrefix(): void
    {
        $pool = new ArrayCachePool();
        $cache = new CollectionCache($pool);

        $cache->setIdList('some-key', [1], 60);

        $this->assertArrayHasKey('l2q:some-key', $pool->items, 'CollectionCache must namespace id-list keys under the l2q: prefix.');
    }

    public function testAddToReverseIndexThenGetReturnsTheCacheKey(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);

        $this->assertSame(['cache-key-a'], $cache->getReverseIndexCacheKeys('products', 1));
    }

    public function testReverseIndexAccumulatesMultipleCacheKeysForSameRow(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);
        $cache->addToReverseIndex('products', 1, 'cache-key-b', 60);

        $this->assertSame(['cache-key-a', 'cache-key-b'], $cache->getReverseIndexCacheKeys('products', 1));
    }

    public function testAddToReverseIndexDoesNotDuplicateTheSameCacheKey(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);
        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);

        $this->assertSame(['cache-key-a'], $cache->getReverseIndexCacheKeys('products', 1));
    }

    public function testReverseIndexIsScopedByTableNotJustRawId(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);
        $cache->addToReverseIndex('orders', 1, 'cache-key-b', 60);

        $this->assertSame(['cache-key-a'], $cache->getReverseIndexCacheKeys('products', 1));
        $this->assertSame(['cache-key-b'], $cache->getReverseIndexCacheKeys('orders', 1));
    }

    public function testReverseIndexIsScopedByNormalizedIdTypeTag(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-int', 60);
        $cache->addToReverseIndex('products', '1', 'cache-key-string', 60);

        $this->assertSame(['cache-key-int'], $cache->getReverseIndexCacheKeys('products', 1));
        $this->assertSame(['cache-key-string'], $cache->getReverseIndexCacheKeys('products', '1'));
    }

    public function testGetReverseIndexCacheKeysMissesWhenNeverWritten(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $this->assertNull($cache->getReverseIndexCacheKeys('products', 999));
    }

    public function testEvictReverseIndexRemovesTheEntry(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);
        $cache->evictReverseIndex('products', 1);

        $this->assertNull($cache->getReverseIndexCacheKeys('products', 1));
    }

    public function testReverseIndexEntryTtlIsQueryEntryTtlPlusGraceBuffer(): void
    {
        $pool = new ArrayCachePool();
        $cache = new CollectionCache($pool);

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);

        $item = $pool->items['l2qidx:products:i:1'];
        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame(60 + CollectionCache::REVERSE_INDEX_GRACE_SECONDS, $item->expiresAfterSeconds);
    }

    public function testReverseIndexKeysAreStoredUnderTheReverseIndexPrefix(): void
    {
        $pool = new ArrayCachePool();
        $cache = new CollectionCache($pool);

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);

        $this->assertArrayHasKey('l2qidx:products:i:1', $pool->items, 'Reverse-index entries must be namespaced under the l2qidx: prefix.');
    }

    public function testAddToReverseIndexIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);
        $this->addToAssertionCount(1);
    }

    public function testGetReverseIndexCacheKeysIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $this->assertNull($cache->getReverseIndexCacheKeys('products', 1));
    }

    public function testEvictReverseIndexIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $cache->evictReverseIndex('products', 1);
        $this->addToAssertionCount(1);
    }

    public function testAddToReverseIndexActuallyPersistsViaPoolSave(): void
    {
        $pool = new SaveOnlyCachePool();
        $cache = new CollectionCache($pool);

        $cache->addToReverseIndex('products', 1, 'cache-key-a', 60);

        $this->assertSame(1, $pool->saveCallCount, 'addToReverseIndex() must call the pool\'s save() to actually persist — a local mutation alone is not a write.');
    }

    public function testCheckReverseIndexExistenceReturnsEveryMissingIdNotJustOne(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $missing = $cache->findMissingReverseIndexIds('products', [1, 2, 3]);

        $this->assertSame([1, 2, 3], $missing, 'All three ids lack a reverse-index entry — every one of them must be reported missing, not just the first.');
    }

    public function testCheckReverseIndexExistenceReturnsWhichIdsAreMissing(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'some-key', 60);
        $cache->addToReverseIndex('products', 2, 'some-key', 60);

        $missing = $cache->findMissingReverseIndexIds('products', [1, 2, 3]);

        $this->assertSame([3], $missing);
    }

    public function testCheckReverseIndexExistenceUsesBatchedGetItemsNotPerIdRoundTrips(): void
    {
        $pool = new BatchCountingCachePool();
        $cache = new CollectionCache($pool);

        $cache->addToReverseIndex('products', 1, 'some-key', 60);
        $pool->getItemCallCount = 0;
        $pool->getItemsCallCount = 0;
        $cache->findMissingReverseIndexIds('products', [1, 2, 3]);

        $this->assertSame(1, $pool->getItemsCallCount, 'Existence check must use a single batched getItems() call, not N round-trips.');
        $this->assertSame(0, $pool->getItemCallCount, 'Existence check must not call getItem() per id.');
    }

    public function testCheckReverseIndexExistenceReturnsEmptyWhenAllPresent(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $cache->addToReverseIndex('products', 1, 'some-key', 60);
        $cache->addToReverseIndex('products', 2, 'some-key', 60);

        $this->assertSame([], $cache->findMissingReverseIndexIds('products', [1, 2]));
    }

    public function testCheckReverseIndexExistenceIsGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $this->assertSame([1, 2], $cache->findMissingReverseIndexIds('products', [1, 2]));
    }
}
