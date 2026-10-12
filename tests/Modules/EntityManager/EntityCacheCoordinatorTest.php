<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\EntityManager\EntityCacheCoordinator;
use Articulate\Schema\EntityMetadataRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[Entity(tableName: 'ecc_products')]
class EccProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name = '';
}

#[Entity(tableName: 'ecc_products')]
class EccProductSummary {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name = '';
}

#[Entity(tableName: 'ecc_orders')]
class EccOrder {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $reference = '';
}

/**
 * Records every reverse-index/eviction call so tests can assert exactly
 * what EntityCacheCoordinator's flush-time invalidation decided to evict —
 * needed to kill MethodCallRemoval-style mutants an outcome-only assertion
 * can't see, and lets us assert unrelated-table isolation without a real
 * PSR-6 backend.
 */
class EntityCacheCoordinatorSpyCollectionCache implements CollectionCacheInterface {
    /** @var array<string, list<string>> keyed by "$table:$id" */
    public array $reverseIndex = [];

    /** @var list<string> */
    public array $evictedIdListKeys = [];

    /** @var list<array{table: string, id: mixed}> */
    public array $evictedReverseIndexEntries = [];

    /**
     * @param list<string> $cacheKeys
     */
    public function seedReverseIndex(string $tableName, mixed $id, array $cacheKeys): void
    {
        $this->reverseIndex[$tableName . ':' . $id] = $cacheKeys;
    }

    public function getIdList(string $key): ?array
    {
        return null;
    }

    public function setIdList(string $key, array $ids, int $ttl): void
    {
    }

    public function evictIdList(string $key): void
    {
        $this->evictedIdListKeys[] = $key;
    }

    public function addToReverseIndex(string $tableName, mixed $id, string $cacheKey, int $ttl): void
    {
        $this->reverseIndex[$tableName . ':' . $id][] = $cacheKey;
    }

    public function getReverseIndexCacheKeys(string $tableName, mixed $id): ?array
    {
        return $this->reverseIndex[$tableName . ':' . $id] ?? null;
    }

    public function evictReverseIndex(string $tableName, mixed $id): void
    {
        $this->evictedReverseIndexEntries[] = ['table' => $tableName, 'id' => $id];
        unset($this->reverseIndex[$tableName . ':' . $id]);
    }

    public function findMissingReverseIndexIds(string $tableName, array $ids): array
    {
        return [];
    }
}

class EntityCacheCoordinatorArrayItem implements CacheItemInterface {
    public function __construct(
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        return $this;
    }
}

class EntityCacheCoordinatorArrayPool implements CacheItemPoolInterface {
    /** @var array<string, EntityCacheCoordinatorArrayItem> */
    private array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ?? new EntityCacheCoordinatorArrayItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
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

class EntityCacheCoordinatorTest extends TestCase {
    public function testSharedPoolGenerationSeenBySecondCoordinator(): void
    {
        $pool = new EntityCacheCoordinatorArrayPool();
        $coordinator1 = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());
        $coordinator2 = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());

        $this->assertSame(0, $coordinator1->readQueryCacheGeneration());
        $this->assertSame(0, $coordinator2->readQueryCacheGeneration());

        $coordinator1->incrementQueryCacheGeneration();

        $this->assertSame(1, $coordinator2->readQueryCacheGeneration());
    }

    public function testMultipleIncrementsAreMonotonic(): void
    {
        $pool = new EntityCacheCoordinatorArrayPool();
        $coordinator = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());

        $gen0 = $coordinator->readQueryCacheGeneration();
        $coordinator->incrementQueryCacheGeneration();
        $gen1 = $coordinator->readQueryCacheGeneration();
        $coordinator->incrementQueryCacheGeneration();
        $gen2 = $coordinator->readQueryCacheGeneration();

        $this->assertGreaterThan($gen0, $gen1);
        $this->assertGreaterThan($gen1, $gen2);
    }

    /**
     * Pins the exact increment amount and return type (int, not the raw
     * string a CacheItemInterface::get() could hand back) — a
     * greater-than-only assertion (testMultipleIncrementsAreMonotonic)
     * can't catch a mutant that increments by 2 instead of 1, or that
     * drops the (int) cast, since both still produce a strictly-increasing
     * sequence.
     */
    public function testIncrementQueryCacheGenerationIncrementsByExactlyOne(): void
    {
        $pool = new EntityCacheCoordinatorArrayPool();
        $coordinator = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());

        $coordinator->incrementQueryCacheGeneration();
        $this->assertSame(1, $coordinator->readQueryCacheGeneration());

        $coordinator->incrementQueryCacheGeneration();
        $this->assertSame(2, $coordinator->readQueryCacheGeneration());
    }

    public function testReadQueryCacheGenerationReturnsAnIntegerType(): void
    {
        $pool = new EntityCacheCoordinatorArrayPool();
        $coordinator = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());
        $coordinator->incrementQueryCacheGeneration();

        $this->assertIsInt($coordinator->readQueryCacheGeneration());
    }

    /**
     * Seeds the pool with a numeric STRING (as a hand-rolled PSR-6 backend
     * might legitimately return) to prove readQueryCacheGeneration() really
     * casts to int rather than passing the stored value through — the
     * happy-path tests above never exercise this because
     * incrementQueryCacheGeneration() always stores a genuine int itself.
     */
    public function testReadQueryCacheGenerationCastsAStoredNumericStringToInt(): void
    {
        $pool = new EntityCacheCoordinatorArrayPool();
        $item = $pool->getItem('articulate_qrc_gen');
        $item->set('5');
        $pool->save($item);
        $coordinator = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());

        $this->assertSame(5, $coordinator->readQueryCacheGeneration());
    }

    /**
     * incrementQueryCacheGeneration() reads the current value, adds 1, and
     * stores it back — if a string "5" were used directly in PHP's `+`
     * operator without the (int) cast, the result is still numerically 6
     * (PHP auto-coerces numeric strings in arithmetic), so a plain
     * before/after value assertion can't distinguish cast-dropped from
     * cast-present. This asserts the STORED value's PHP type via a second
     * coordinator reading the same pool, which only sees a strict int if
     * the cast actually happened before the add.
     */
    public function testIncrementQueryCacheGenerationStoresARealIntAfterReadingAStringSeed(): void
    {
        $pool = new EntityCacheCoordinatorArrayPool();
        $item = $pool->getItem('articulate_qrc_gen');
        $item->set('5');
        $pool->save($item);
        $coordinator = new EntityCacheCoordinator($pool, new EntityMetadataRegistry());

        $coordinator->incrementQueryCacheGeneration();

        $rawStoredValue = $pool->getItem('articulate_qrc_gen')->get();
        $this->assertSame(6, $rawStoredValue);
    }

    public function testInvalidateSecondLevelCacheEvictsReverseIndexedQueryCacheEntryOnUpdate(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $collectionCache->seedReverseIndex('ecc_products', 1, ['cache-key-a']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame(['cache-key-a'], $collectionCache->evictedIdListKeys);
    }

    public function testInvalidateSecondLevelCacheEvictsReverseIndexOnDelete(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $collectionCache->seedReverseIndex('ecc_products', 1, ['cache-key-a']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [], 'deletes' => [$entity], 'softDeletes' => []]);

        $this->assertSame(['cache-key-a'], $collectionCache->evictedIdListKeys);
    }

    public function testInvalidateSecondLevelCacheEvictsReverseIndexOnSoftDelete(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $collectionCache->seedReverseIndex('ecc_products', 1, ['cache-key-a']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [], 'deletes' => [], 'softDeletes' => [$entity]]);

        $this->assertSame(['cache-key-a'], $collectionCache->evictedIdListKeys);
    }

    public function testInvalidateSecondLevelCacheEvictsEveryCacheKeyNamedByTheReverseIndex(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $collectionCache->seedReverseIndex('ecc_products', 1, ['cache-key-a', 'cache-key-b']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame(['cache-key-a', 'cache-key-b'], $collectionCache->evictedIdListKeys);
    }

    public function testInvalidateSecondLevelCacheClearsTheReverseIndexEntryItselfAfterEviction(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $collectionCache->seedReverseIndex('ecc_products', 1, ['cache-key-a']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame([['table' => 'ecc_products', 'id' => 1]], $collectionCache->evictedReverseIndexEntries);
        $this->assertNull($collectionCache->getReverseIndexCacheKeys('ecc_products', 1));
    }

    public function testInvalidateSecondLevelCacheIsANoOpWhenNoReverseIndexEntryExists(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 999;

        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame([], $collectionCache->evictedIdListKeys);
    }

    public function testInvalidateSecondLevelCacheThroughASiblingClassEvictsTheSameReverseIndexEntry(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $registry->getMetadata(EccProductSummary::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $collectionCache->seedReverseIndex('ecc_products', 1, ['cache-key-a']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        // Write through the sibling class mapping the SAME table/PK.
        $entity = new EccProductSummary();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame(['cache-key-a'], $collectionCache->evictedIdListKeys, 'A write through a context-bounded sibling class must evict the reverse-indexed cache entry of the same table/row.');
    }

    public function testInvalidateSecondLevelCacheDoesNotEvictAnUnrelatedTablesCacheEntry(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $registry->getMetadata(EccOrder::class);
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        // Same raw id (1), but on a DIFFERENT table — must not collide.
        $collectionCache->seedReverseIndex('ecc_orders', 1, ['order-cache-key']);
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        $entity = new EccProduct();
        $entity->id = 1;

        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame([], $collectionCache->evictedIdListKeys, 'A write to table A must never evict a cached query belonging to an unrelated table B, even with a coincidentally-matching raw id.');
    }

    public function testInvalidateSecondLevelCacheWithoutCollectionCacheConfiguredIsANoOp(): void
    {
        $registry = new EntityMetadataRegistry();
        $registry->getMetadata(EccProduct::class);
        $coordinator = new EntityCacheCoordinator(null, $registry);

        $entity = new EccProduct();
        $entity->id = 1;

        // Must not throw when no collection cache was ever configured.
        $coordinator->invalidateSecondLevelCache(['updates' => [['entity' => $entity]], 'deletes' => [], 'softDeletes' => []]);
        $this->addToAssertionCount(1);
    }
}
