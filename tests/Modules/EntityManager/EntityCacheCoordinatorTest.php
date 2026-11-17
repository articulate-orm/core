<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\EntityManager\EntityCacheCoordinator;
use Articulate\Modules\EntityManager\EntityUpdate;
use Articulate\Modules\EntityManager\MergedUpdate;
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
 * Records every generation bump so tests can assert exactly which tables
 * EntityCacheCoordinator's flush-time invalidation bumped, and how often —
 * needed to kill mutants an outcome-only round trip can't see.
 */
class EntityCacheCoordinatorSpyCollectionCache implements CollectionCacheInterface {
    /** @var list<list<string>> */
    public array $incrementCalls = [];

    public function getIdList(string $key): ?array
    {
        return null;
    }

    public function setIdList(string $key, array $ids, int $ttl): void
    {
    }

    public function getTableGenerations(array $tableNames): ?array
    {
        return array_fill_keys($tableNames, 1);
    }

    public function incrementTableGenerations(array $tableNames): void
    {
        $this->incrementCalls[] = $tableNames;
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

    public function testEveryKindOfWriteBumpsItsTablesGeneration(): void
    {
        $registry = new EntityMetadataRegistry();
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $coordinator = new EntityCacheCoordinator(null, $registry, collectionCache: $collectionCache);

        foreach (['inserts', 'deletes', 'softDeletes'] as $kind) {
            $changes = ['inserts' => [], 'updates' => [], 'deletes' => [], 'softDeletes' => []];
            $changes[$kind] = [$this->product(1)];
            $coordinator->bumpWrittenTableGenerations($changes);
        }
        $coordinator->bumpWrittenTableGenerations(['inserts' => [], 'updates' => [new EntityUpdate($this->product(1), [])], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame([['ecc_products'], ['ecc_products'], ['ecc_products'], ['ecc_products']], $collectionCache->incrementCalls);
    }

    public function testAMergedUpdateBumpsItsOwnTableEvenWithoutABoundEntity(): void
    {
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $coordinator = new EntityCacheCoordinator(null, new EntityMetadataRegistry(), collectionCache: $collectionCache);

        $coordinator->bumpWrittenTableGenerations([
            'inserts' => [],
            'updates' => [new MergedUpdate('ecc_products', null, ['name' => 'x'], 'id = ?', [1])],
            'deletes' => [],
            'softDeletes' => [],
        ]);

        $this->assertSame([['ecc_products']], $collectionCache->incrementCalls);
    }

    public function testManyWritesToOneTableInOneFlushBumpItOnceInOneBatch(): void
    {
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $coordinator = new EntityCacheCoordinator(null, new EntityMetadataRegistry(), collectionCache: $collectionCache);

        $summary = new EccProductSummary();
        $summary->id = 2;

        $coordinator->bumpWrittenTableGenerations([
            'inserts' => [new EccProduct(), new EccProduct(), new EccOrder()],
            'updates' => [new EntityUpdate($summary, []), new MergedUpdate('ecc_products', null, [], 'id = ?', [3])],
            'deletes' => [$this->product(4)],
            'softDeletes' => [$this->product(5)],
        ]);

        $this->assertSame(
            [['ecc_products', 'ecc_orders']],
            $collectionCache->incrementCalls,
            'Several writes to one table (through any sibling class) must bump that table once per flush, in a single batched call.'
        );
    }

    public function testAFlushWithoutWritesBumpsNoTableGeneration(): void
    {
        $collectionCache = new EntityCacheCoordinatorSpyCollectionCache();
        $coordinator = new EntityCacheCoordinator(null, new EntityMetadataRegistry(), collectionCache: $collectionCache);

        $coordinator->bumpWrittenTableGenerations(['inserts' => [], 'updates' => [], 'deletes' => [], 'softDeletes' => []]);

        $this->assertSame([], $collectionCache->incrementCalls);
    }

    public function testInvalidateSecondLevelCacheWithoutCollectionCacheConfiguredIsANoOp(): void
    {
        $coordinator = new EntityCacheCoordinator(null, new EntityMetadataRegistry());

        $coordinator->invalidateSecondLevelCache(['inserts' => [], 'updates' => [new EntityUpdate($this->product(1), [])], 'deletes' => [], 'softDeletes' => []]);
        $this->addToAssertionCount(1);
    }

    private function product(int $id): EccProduct
    {
        $product = new EccProduct();
        $product->id = $id;

        return $product;
    }
}
