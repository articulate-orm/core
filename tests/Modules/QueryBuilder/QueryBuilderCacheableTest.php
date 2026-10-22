<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Exceptions\TransactionRequiredException;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\SecondLevelCache;
use Articulate\QueryLogger\QueryLoggerInterface;
use Articulate\Tests\DatabaseTestCase;
use Articulate\Tests\Modules\QueryBuilder\Fixtures\QbCacheCompositeStock;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class QbCacheItem implements CacheItemInterface {
    private mixed $value = null;

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

class QbArrayCachePool implements CacheItemPoolInterface {
    /** @var array<string, CacheItemInterface> */
    public array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new QbCacheItem($key);
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

class QbCountingCachePool extends QbArrayCachePool {
    public int $getItemCalls = 0;

    public int $getItemsCalls = 0;

    public int $saveCalls = 0;

    public int $saveDeferredCalls = 0;

    public int $commitCalls = 0;

    public function getItem(string $key): CacheItemInterface
    {
        $this->getItemCalls++;

        return $this->items[$key] ??= new QbCacheItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $this->getItemsCalls++;
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->items[$key] ??= new QbCacheItem($key);
        }

        return $result;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->saveCalls++;

        return parent::save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->saveDeferredCalls++;

        return parent::save($item);
    }

    public function commit(): bool
    {
        $this->commitCalls++;

        return true;
    }
}

/**
 * Counts how many SELECT statements actually reached the database, used as
 * the "did this hit the DB" signal for cache-hit assertions.
 */
class SelectCountingLogger implements QueryLoggerInterface {
    public int $selectCount = 0;

    /**
     * @param mixed[] $parameters
     */
    public function log(string $sql, array $parameters, float $durationMs): void
    {
        if (str_starts_with(ltrim($sql), 'SELECT')) {
            $this->selectCount++;
        }
    }
}

#[Entity(tableName: 'qb_cache_products')]
class QbCacheProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public int $price;
}

class QueryBuilderCacheableTest extends DatabaseTestCase {
    #[DataProvider('databaseProvider')]
    public function testCacheableQueryIsServedFromCacheOnSecondCall(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);
        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Gadget', 20]);

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);

        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        $qb = $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable();
        $firstResult = $qb->getResult();

        $this->assertCount(2, $firstResult);
        $this->assertSame(1, $logger->selectCount, 'First call must hit the database exactly once.');

        $em->clear();

        $qb2 = $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable();
        $secondResult = $qb2->getResult();

        $this->assertCount(2, $secondResult);
        $this->assertSame('Widget', $secondResult[0]->name);
        $this->assertSame('Gadget', $secondResult[1]->name);
        $this->assertSame(1, $logger->selectCount, 'Second identical call must be served from cache, not the database.');
    }

    #[DataProvider('databaseProvider')]
    public function testNonCacheableQueryAlwaysHitsDatabase(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);

        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->getResult();
        $em->clear();
        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->getResult();

        $this->assertSame(2, $logger->selectCount, 'Queries without cacheable() must always hit the database.');
    }

    #[DataProvider('databaseProvider')]
    public function testCacheableIsNoOpWhenNoSecondLevelCachePoolConfigured(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);

        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);

        // No secondLevelCache pool configured on the EntityManager.
        $em = new EntityManager($loggedConnection);

        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();
        $em->clear();
        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertSame(2, $logger->selectCount, 'cacheable() must be a silent no-op without a configured L2 pool.');
    }

    #[DataProvider('databaseProvider')]
    public function testCacheableIsNoOpForAggregateQueries(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);

        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        // count() bypasses ObjectHydrator — cacheable() must be a silent no-op here.
        $em->createQueryBuilder(QbCacheProduct::class)->count()->cacheable()->getResult();
        $em->createQueryBuilder(QbCacheProduct::class)->count()->cacheable()->getResult();

        $this->assertSame(2, $logger->selectCount, 'Aggregate queries must never be served from the collection cache.');
    }

    /**
     * Resolving a cacheable() cache hit must actually register the entity in
     * the identity map (not just hydrate it ephemerally) — otherwise a
     * follow-up find() for the same id would issue a redundant database
     * read instead of reusing the already-resolved instance.
     */
    #[DataProvider('databaseProvider')]
    public function testCacheResolvedEntityIsRegisteredInIdentityMap(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_cache_products_id_seq' : null);

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);

        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        // First call: DB miss, populates both the collection cache and (via
        // QueryResultExecutor::cacheFreshRow) the per-entity L2 cache.
        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();

        // Clear the identity map only — the collection cache and per-entity
        // L2 cache survive, so the next getResult() resolves via tryResolve()'s
        // per-entity-cache branch (not the identity-map branch).
        $em->clear();

        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();
        $selectCountAfterCacheResolvedCall = $logger->selectCount;

        // find() for the same id must now return the exact instance
        // registerManaged() put in the identity map above, with zero
        // additional database reads.
        $entity = $em->find(QbCacheProduct::class, $id);

        $this->assertNotNull($entity);
        $this->assertSame('Widget', $entity->name);
        $this->assertSame(
            $selectCountAfterCacheResolvedCall,
            $logger->selectCount,
            'find() for an id already resolved via the cache must hit the identity map, not the database.'
        );
    }

    #[DataProvider('databaseProvider')]
    public function testCacheableWithLockBypassesCacheAndStillLocks(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);
        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        if (!$loggedConnection->inTransaction()) {
            $loggedConnection->beginTransaction();
        }

        $first = $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->lock()->cacheable();
        $this->assertStringEndsWith('FOR UPDATE', $first->getSQL());
        $first->getResult();
        $em->clear();
        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->lock()->cacheable()->getResult();

        $this->assertSame(2, $logger->selectCount, 'A locked query must always reach the database.');
        $this->assertSame([], array_filter(array_keys($cache->items), fn (string $key): bool => $cache->hasItem($key)), 'A locked query must not write to the pool.');
    }

    #[DataProvider('databaseProvider')]
    public function testCacheableWithLockOutsideTransactionStillThrows(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $this->expectException(TransactionRequiredException::class);

        $em = new EntityManager($connection, secondLevelCache: new QbArrayCachePool());
        $em->createQueryBuilder(QbCacheProduct::class)->lock()->cacheable()->getResult();
    }

    #[DataProvider('databaseProvider')]
    public function testCompositePrimaryKeyEntitiesAreNotIdListCached(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createStockTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_stock (warehouse_id, product_id, quantity) VALUES (1, 1, 5)');
        $connection->executeQuery('INSERT INTO qb_cache_stock (warehouse_id, product_id, quantity) VALUES (1, 2, 7)');

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);
        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        $first = $em->createQueryBuilder(QbCacheCompositeStock::class)->orderBy('product_id')->cacheable()->getResult();
        $em->clear();
        $second = $em->createQueryBuilder(QbCacheCompositeStock::class)->orderBy('product_id')->cacheable()->getResult();

        $this->assertCount(2, $first);
        $this->assertSame([5, 7], array_map(fn (object $stock): int => $stock->quantity, $second));
        $this->assertSame(2, $logger->selectCount);
        $this->assertSame([], array_filter(array_keys($cache->items), fn (string $key): bool => $cache->hasItem($key)), 'Nothing may be written to the pool for composite-key entities.');
    }

    #[DataProvider('databaseProvider')]
    public function testRowsWithNonEntityColumnsAreNotPouredIntoEntityCache(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->createLabelTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_cache_products_id_seq' : null);
        $connection->executeQuery('INSERT INTO qb_cache_labels (product_id, label) VALUES (?, ?)', [$id, 'sale']);

        $cache = new QbArrayCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);
        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        $build = fn () => $em->createQueryBuilder(QbCacheProduct::class)
            ->select('*')
            ->join('qb_cache_labels', 'qb_cache_labels.product_id = qb_cache_products.id')
            ->cacheable();

        $this->assertCount(1, $build()->getResult());

        $entityKey = (new SecondLevelCache($cache, 3600, $loggedConnection->getCacheNamespace()))->generateKey(QbCacheProduct::class, $id);
        $this->assertFalse($cache->hasItem($entityKey), 'A joined row must not be cached as the entity\'s own row.');

        $em->clear();
        $this->assertCount(1, $build()->getResult());
        $this->assertSame(2, $logger->selectCount, 'Unresolvable ids must fall back to the database.');
    }

    #[DataProvider('databaseProvider')]
    public function testCacheHitUsesOneBatchReadAndMissUsesOneCommit(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);

        foreach (['A', 'B', 'C'] as $name) {
            $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', [$name, 1]);
        }

        $cache = new QbCountingCachePool();
        $logger = new SelectCountingLogger();
        $loggedConnection = $this->withLogger($connection, $databaseName, $logger);
        $em = new EntityManager($loggedConnection, secondLevelCache: $cache);

        $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertSame(3, $cache->saveDeferredCalls, 'Entity rows are queued, one per row.');
        $this->assertSame(1, $cache->commitCalls, 'and flushed with a single commit.');
        $this->assertSame(1, $cache->saveCalls, 'Only the id-list is saved individually.');

        $em->clear();
        $getItemBefore = $cache->getItemCalls;
        $getItemsBefore = $cache->getItemsCalls;

        $result = $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertSame(['A', 'B', 'C'], array_map(fn (object $p): string => $p->name, $result));
        $this->assertSame(1, $logger->selectCount);
        $this->assertSame(1, $cache->getItemCalls - $getItemBefore, 'Only the id-list lookup uses getItem().');
        $this->assertSame(1, $cache->getItemsCalls - $getItemsBefore, 'All entities resolve in one getItems() call.');
    }

    public function testConnectionsToDifferentDatabasesGetDifferentCacheNamespaces(): void
    {
        $mysql = $this->getConnection('mysql');
        $pgsql = $this->getConnection('pgsql');

        $this->assertNotSame($mysql->getCacheNamespace(), $pgsql->getCacheNamespace());
        $this->assertSame($mysql->getCacheNamespace(), $mysql->getCacheNamespace());

        $pool = new QbArrayCachePool();
        $this->assertNotSame(
            (new SecondLevelCache($pool, 3600, $mysql->getCacheNamespace()))->generateKey(QbCacheProduct::class, 1),
            (new SecondLevelCache($pool, 3600, $pgsql->getCacheNamespace()))->generateKey(QbCacheProduct::class, 1),
        );
    }

    #[DataProvider('databaseProvider')]
    public function testEntityManagersOnDifferentDatabasesDoNotShareCacheEntries(string $databaseName): void
    {
        $connection = $this->getConnection($databaseName);
        $this->createProductTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $connection->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Widget', 10]);

        $otherName = $databaseName === 'mysql' ? 'pgsql' : 'mysql';
        $other = $this->getConnection($otherName);
        $this->createProductTable($other, $otherName);
        $other->executeQuery('INSERT INTO qb_cache_products (name, price) VALUES (?, ?)', ['Gizmo', 99]);

        $shared = new QbArrayCachePool();
        $em = new EntityManager($connection, secondLevelCache: $shared);
        $otherEm = new EntityManager($other, secondLevelCache: $shared);

        $mine = $em->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();
        $theirs = $otherEm->createQueryBuilder(QbCacheProduct::class)->orderBy('id')->cacheable()->getResult();

        $this->assertSame('Widget', $mine[0]->name);
        $this->assertSame('Gizmo', $theirs[0]->name);
    }

    private function createStockTable(Connection $connection, string $databaseName): void
    {
        $connection->executeQuery('DROP TABLE IF EXISTS qb_cache_stock' . ($databaseName === 'pgsql' ? ' CASCADE' : ''));
        $connection->executeQuery(
            'CREATE TABLE qb_cache_stock (warehouse_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL, PRIMARY KEY (warehouse_id, product_id))'
        );
    }

    private function createLabelTable(Connection $connection, string $databaseName): void
    {
        $connection->executeQuery('DROP TABLE IF EXISTS qb_cache_labels' . ($databaseName === 'pgsql' ? ' CASCADE' : ''));
        $connection->executeQuery('CREATE TABLE qb_cache_labels (product_id INT NOT NULL, label VARCHAR(255) NOT NULL)');
    }

    private function createProductTable(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_cache_products CASCADE');
            $connection->executeQuery(
                'CREATE TABLE qb_cache_products (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL)'
            );
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_cache_products');
            $connection->executeQuery(
                'CREATE TABLE qb_cache_products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL)'
            );
        }
    }

    /**
     * The shared Connection from ConnectionPool has no logger wired in and is
     * already mid-transaction with our fixture rows. Inject the spy logger
     * into the existing instance via reflection rather than opening a second
     * PDO connection, which would not see this test's uncommitted inserts.
     */
    private function withLogger(Connection $connection, string $databaseName, QueryLoggerInterface $logger): Connection
    {
        $property = new \ReflectionProperty(Connection::class, 'queryLogger');
        $property->setAccessible(true);
        $property->setValue($connection, $logger);

        return $connection;
    }
}
