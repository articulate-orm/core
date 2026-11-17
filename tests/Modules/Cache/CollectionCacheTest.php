<?php

namespace Articulate\Tests\Modules\Cache;

use Articulate\Modules\Cache\CollectionCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

        $this->assertArrayHasKey('l2q_some-key', $pool->items, 'CollectionCache must namespace id-list keys under the l2q_ prefix.');
    }

    public function testRoundTripsThroughAPoolThatValidatesPsr6Keys(): void
    {
        $pool = new ArrayAdapter();
        $cache = new CollectionCache($pool);

        $cache->setIdList('cq_' . hash('sha256', 'x'), [1, 2, 3], 60);

        $this->assertSame([1, 2, 3], $cache->getIdList('cq_' . hash('sha256', 'x')));
        $this->assertTrue($pool->hasItem('l2q_cq_' . hash('sha256', 'x')));
    }

    public function testKeyPrefixHasNoPsr6ReservedCharacters(): void
    {
        $pool = new SaveOnlyCachePool();
        $cache = new CollectionCache($pool);

        $cache->setIdList('k', [1], 60);

        foreach (array_keys($pool->saved) as $key) {
            $this->assertSame(0, preg_match('/[{}()\\/\\\\@:]/', $key), $key);
        }
    }

    public function testIncrementTableGenerationsAdvancesOnlyTheNamedTables(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());
        $before = $cache->getTableGenerations(['orders', 'posts', 'products']);

        $cache->incrementTableGenerations(['products', 'posts']);

        $this->assertSame(
            ['orders' => $before['orders'], 'posts' => $before['posts'] + 1, 'products' => $before['products'] + 1],
            $cache->getTableGenerations(['orders', 'posts', 'products'])
        );
    }

    public function testADuplicatedTableIsIncrementedOnce(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());
        $before = $cache->getTableGenerations(['products'])['products'];

        $cache->incrementTableGenerations(['products', 'products']);

        $this->assertSame($before + 1, $cache->getTableGenerations(['products'])['products']);
    }

    public function testTableGenerationsAreStableAcrossReads(): void
    {
        $cache = new CollectionCache(new ArrayCachePool());

        $this->assertSame($cache->getTableGenerations(['products', 'posts']), $cache->getTableGenerations(['products', 'posts']));
    }

    public function testTableGenerationsAreReadInOneBatchedRoundTrip(): void
    {
        $pool = new BatchCountingCachePool();
        $cache = new CollectionCache($pool);

        $cache->getTableGenerations(['products', 'posts', 'tags']);

        $this->assertSame(1, $pool->getItemsCallCount);
        $this->assertSame(0, $pool->getItemCallCount);
    }

    public function testReadingNoTablesIsEmptyWithoutARoundTrip(): void
    {
        $pool = new BatchCountingCachePool();
        $cache = new CollectionCache($pool);

        $this->assertSame([], $cache->getTableGenerations([]));
        $cache->incrementTableGenerations([]);
        $this->assertSame(0, $pool->getItemsCallCount);
    }

    public function testTableGenerationIsSharedThroughThePool(): void
    {
        $pool = new ArrayCachePool();
        $writer = new CollectionCache($pool);
        $reader = new CollectionCache($pool);
        $before = $reader->getTableGenerations(['products'])['products'];

        $writer->incrementTableGenerations(['products']);

        $this->assertSame($before + 1, $reader->getTableGenerations(['products'])['products']);
    }

    public function testTableGenerationIsScopedByNamespace(): void
    {
        $pool = new ArrayCachePool();
        $tenantA = new CollectionCache($pool, 'tenant-a');
        $tenantB = new CollectionCache($pool, 'tenant-b');
        $before = $tenantB->getTableGenerations(['products']);

        $tenantA->incrementTableGenerations(['products']);

        $this->assertSame($before, $tenantB->getTableGenerations(['products']));
    }

    public function testTableGenerationKeysAreAcceptedByAStrictPsr6Pool(): void
    {
        $cache = new CollectionCache(new ArrayAdapter());
        $before = $cache->getTableGenerations(['products']);

        $cache->incrementTableGenerations(['products']);

        $this->assertNotNull($before);
        $this->assertSame($before['products'] + 1, $cache->getTableGenerations(['products'])['products']);
    }

    public function testAnEvictedGenerationCounterNeverComesBackAsAGenerationAlreadyKeyedAgainst(): void
    {
        $pool = new ArrayCachePool();
        $cache = new CollectionCache($pool);
        $seen = [$cache->getTableGenerations(['products'])['products']];
        $cache->incrementTableGenerations(['products']);
        $seen[] = $cache->getTableGenerations(['products'])['products'];

        // The backend drops the counter (LRU / restart) while id-lists keyed by older generations may survive.
        $pool->clear();

        $this->assertNotContains(
            $cache->getTableGenerations(['products'])['products'],
            $seen,
            'Restarting the counter at a previously used value would let a stale id-list cached under that generation match again.'
        );
    }

    public function testAnIncrementOfAMissingCounterSeedsItAwayFromZero(): void
    {
        $pool = new ArrayCachePool();
        $cache = new CollectionCache($pool);

        $cache->incrementTableGenerations(['products']);
        $pool->clear();
        $cache->incrementTableGenerations(['products']);

        $this->assertGreaterThan(1, $cache->getTableGenerations(['products'])['products']);
    }

    public function testTableGenerationsAreGracefulOnBackendFailure(): void
    {
        $cache = new CollectionCache(new FailingCachePool());

        $cache->incrementTableGenerations(['products']);
        $this->assertNull($cache->getTableGenerations(['products']));
    }
}
