<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\SoftDeleteable;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\QueryBuilder\Filter\SoftDeleteFilter;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class QbInvItem implements CacheItemInterface {
    private mixed $value = null;

    private ?int $expiration = null;

    private bool $isHit = false;

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
        if ($this->expiration !== null && $this->expiration < QbInvClock::now()) {
            $this->isHit = false;

            return false;
        }

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
        $this->expiration = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiration = null;
        } elseif (is_int($time)) {
            $this->expiration = QbInvClock::now() + $time;
        } else {
            $this->expiration = (new \DateTime('@' . QbInvClock::now()))->add($time)->getTimestamp();
        }

        return $this;
    }
}

/**
 * Controllable clock test double — lets the reverse-index-TTL test assert
 * "still alive at query-TTL + grace minus one second" and "gone past
 * query-TTL + grace" deterministically, without a real sleep().
 */
class QbInvClock {
    private static int $offset = 0;

    public static function now(): int
    {
        return time() + self::$offset;
    }

    public static function advance(int $seconds): void
    {
        self::$offset += $seconds;
    }

    public static function reset(): void
    {
        self::$offset = 0;
    }
}

class QbInvArrayCachePool implements CacheItemPoolInterface {
    /** @var array<string, CacheItemInterface> */
    public array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new QbInvItem($key);
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

#[Entity(tableName: 'qb_inv_products')]
class QbInvProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public int $price;
}

/**
 * Sibling entity class mapping the SAME table/PK as QbInvProduct — a write
 * through this class must still invalidate a cached query built through
 * QbInvProduct (context-bounded sibling invalidation, ADR-0002).
 */
#[Entity(tableName: 'qb_inv_products')]
class QbInvProductAdminView {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public int $price;
}

#[Entity(tableName: 'qb_inv_products')]
#[SoftDeleteable(fieldName: 'deletedAt', columnName: 'deleted_at')]
class QbInvSoftDeleteProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public int $price;

    #[Property]
    public ?\DateTimeImmutable $deletedAt = null;
}

#[Entity(tableName: 'qb_inv_orders')]
class QbInvOrder {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $reference;
}

/**
 * Integration coverage for the exact, reverse-indexed invalidation required
 * by ADR-0002 / articulate-orm/core#39: caching a cacheable() query then
 * mutating a matched row must evict exactly that query's cache entry, never
 * a blunt table-wide sweep, and never cross-contaminate an unrelated table.
 */
class QueryBuilderCacheableInvalidationTest extends DatabaseTestCase {
    protected function setUp(): void
    {
        parent::setUp();
        QbInvClock::reset();
    }

    protected function tearDown(): void
    {
        QbInvClock::reset();
        parent::tearDown();
    }

    #[DataProvider('databaseProvider')]
    public function testUpdatingACachedRowAndFlushingEvictsTheQueryCacheEntry(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_inv_products (name, price) VALUES (?, ?)', ['Widget', 10]);
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_inv_products_id_seq' : null);

        $cache = new QbInvArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $firstResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();
        $this->assertCount(1, $firstResult);
        $this->assertSame('Widget', $firstResult[0]->name);

        $entity = $em->find(QbInvProduct::class, $id);
        $entity->name = 'Widget Updated';
        $em->persist($entity);
        $em->flush();
        $em->clear();

        $secondResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertCount(1, $secondResult);
        $this->assertSame('Widget Updated', $secondResult[0]->name, 'A re-run after updating a cached row must miss the cache and hit the database again.');
    }

    #[DataProvider('databaseProvider')]
    public function testHardDeletingACachedRowAndFlushingEvictsTheQueryCacheEntry(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_inv_products (name, price) VALUES (?, ?), (?, ?)', ['Widget', 10, 'Gadget', 20]);

        $cache = new QbInvArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $firstResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();
        $this->assertCount(2, $firstResult);

        $gadget = $firstResult[1];
        $em->remove($gadget);
        $em->flush();
        $em->clear();

        $secondResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertCount(1, $secondResult, 'A re-run after deleting a cached row must miss the cache and hit the database again.');
        $this->assertSame('Widget', $secondResult[0]->name);
    }

    #[DataProvider('databaseProvider')]
    public function testSoftDeletingACachedRowAndFlushingEvictsTheQueryCacheEntry(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createSoftDeleteProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_inv_products (name, price, deleted_at) VALUES (?, ?, ?), (?, ?, ?)', ['Widget', 10, null, 'Gadget', 20, null]);

        $cache = new QbInvArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);
        $em->getFilters()->add('soft_delete', new SoftDeleteFilter());

        $firstResult = $em->createQueryBuilder(QbInvSoftDeleteProduct::class)->orderBy('id')->cacheable()->getResult();
        $this->assertCount(2, $firstResult);

        $gadget = $firstResult[1];
        $em->remove($gadget);
        $em->flush();
        $em->clear();

        $secondResult = $em->createQueryBuilder(QbInvSoftDeleteProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertCount(1, $secondResult, 'A re-run after soft-deleting a cached row must miss the cache and hit the database again.');
        $this->assertSame('Widget', $secondResult[0]->name);
    }

    #[DataProvider('databaseProvider')]
    public function testWriteThroughAContextBoundedSiblingClassEvictsTheCachedQuery(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_inv_products (name, price) VALUES (?, ?)', ['Widget', 10]);
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_inv_products_id_seq' : null);

        $cache = new QbInvArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        // Cache the query via QbInvProduct...
        $firstResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();
        $this->assertCount(1, $firstResult);

        // ...then write to the SAME row through a sibling class mapping the same table/PK.
        $adminView = $em->find(QbInvProductAdminView::class, $id);
        $this->assertNotNull($adminView);
        $adminView->name = 'Widget Updated Via Sibling';
        $em->persist($adminView);
        $em->flush();
        $em->clear();

        $secondResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertSame(
            'Widget Updated Via Sibling',
            $secondResult[0]->name,
            'A write through a context-bounded sibling class must invalidate a cached query reached through a different sibling mapping the same table/row.'
        );
    }

    #[DataProvider('databaseProvider')]
    public function testWritingToAnUnrelatedTableDoesNotEvictTheCachedQuery(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->createOrderTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_inv_products (name, price) VALUES (?, ?)', ['Widget', 10]);

        $cache = new QbInvArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $firstResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();
        $this->assertCount(1, $firstResult);
        $firstIdentity = spl_object_id($firstResult[0]);

        // Insert, update and delete rows on a completely unrelated table.
        $order = new QbInvOrder();
        $order->reference = 'ORD-1';
        $em->persist($order);
        $em->flush();

        $order->reference = 'ORD-1-updated';
        $em->persist($order);
        $em->flush();

        $em->remove($order);
        $em->flush();
        $em->clear();

        $secondResult = $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertCount(1, $secondResult);
        $this->assertSame(
            'Widget',
            $secondResult[0]->name,
            'A write to an unrelated table must never evict table A\'s cached query.'
        );
        $this->assertNotSame(
            $firstIdentity,
            spl_object_id($secondResult[0]),
            'Sanity check: clear() really did drop the identity map so this assertion is meaningful.'
        );
    }

    #[DataProvider('databaseProvider')]
    public function testReverseIndexEntryOutlivesTheQueryCacheEntryItProtects(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_inv_products (name, price) VALUES (?, ?)', ['Widget', 10]);
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_inv_products_id_seq' : null);

        $queryTtl = 60;
        $cache = new QbInvArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $em->createQueryBuilder(QbInvProduct::class)->orderBy('id')->cacheable($queryTtl)->getResult();

        $tableName = 'qb_inv_products';
        $reverseIndexKey = 'l2qidx:' . $tableName . ':i:' . $id;
        $this->assertTrue($cache->hasItem($reverseIndexKey), 'Reverse-index entry must exist right after the query is cached.');

        // Advance the controllable clock past the query-cache TTL but still
        // inside the reverse-index's TTL + grace buffer window.
        QbInvClock::advance($queryTtl + 1);

        $this->assertTrue(
            $cache->hasItem($reverseIndexKey),
            'Reverse-index entry must still be alive after the query-cache entry\'s own TTL has elapsed — it must outlive what it protects.'
        );
    }

    private function createProductTable(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_inv_products CASCADE');
            $connection->executeQuery(
                'CREATE TABLE qb_inv_products (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL)'
            );
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_inv_products');
            $connection->executeQuery(
                'CREATE TABLE qb_inv_products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL)'
            );
        }
    }

    private function createSoftDeleteProductTable(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_inv_products CASCADE');
            $connection->executeQuery(
                'CREATE TABLE qb_inv_products (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL, deleted_at TIMESTAMP NULL)'
            );
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_inv_products');
            $connection->executeQuery(
                'CREATE TABLE qb_inv_products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL, deleted_at DATETIME NULL)'
            );
        }
    }

    private function createOrderTable(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_inv_orders CASCADE');
            $connection->executeQuery(
                'CREATE TABLE qb_inv_orders (id SERIAL PRIMARY KEY, reference VARCHAR(255) NOT NULL)'
            );
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_inv_orders');
            $connection->executeQuery(
                'CREATE TABLE qb_inv_orders (id INT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(255) NOT NULL)'
            );
        }
    }
}
