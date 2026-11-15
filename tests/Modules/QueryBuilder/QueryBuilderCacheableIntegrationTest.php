<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\SoftDeleteable;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\QueryBuilder\Filter\SoftDeleteFilter;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\QueryLogger\QueryLoggerInterface;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class QbIntCacheBackendDown extends \RuntimeException implements CacheException {
}

class QbIntCacheItem implements CacheItemInterface {
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

/**
 * In-memory pool that behaves until switched $down, then throws on every
 * operation — simulates the cache backend going away mid-iteration.
 */
class QbIntCachePool implements CacheItemPoolInterface {
    public bool $down = false;

    /** @var array<string, CacheItemInterface> */
    private array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        $this->failIfDown();

        return $this->items[$key] ??= new QbIntCacheItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $this->failIfDown();
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->items[$key] ??= new QbIntCacheItem($key);
        }

        return $result;
    }

    public function hasItem(string $key): bool
    {
        $this->failIfDown();

        return isset($this->items[$key]) && $this->items[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->failIfDown();
        $this->items = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        $this->failIfDown();
        unset($this->items[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        $this->failIfDown();
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->failIfDown();
        $this->items[$item->getKey()] = $item;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        $this->failIfDown();

        return true;
    }

    private function failIfDown(): void
    {
        if ($this->down) {
            throw new QbIntCacheBackendDown('Cache backend unavailable.');
        }
    }
}

class QbIntSelectCountingLogger implements QueryLoggerInterface {
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

#[Entity(tableName: 'qb_int_products')]
class QbIntProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'qb_int_products')]
#[SoftDeleteable(fieldName: 'deletedAt', columnName: 'deleted_at')]
class QbIntSoftDeleteProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public ?\DateTime $deletedAt = null;
}

/**
 * Verifies that the collection cache's "free by design" interactions hold
 * when combined: chunk() paging over a cacheable() query, filter-state
 * toggling, per-table insert generations and cache-backend failure
 * (ADR-0002, articulate-orm/core#41).
 */
class QueryBuilderCacheableIntegrationTest extends DatabaseTestCase {
    private const PRODUCT_NAMES = ['P1', 'P2', 'P3', 'P4', 'P5'];

    #[DataProvider('databaseProvider')]
    public function testChunkOverCacheableQueryCachesEachPageIndependently(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName);
        $logger = $this->attachLogger($connection);
        $em = new EntityManager($connection, secondLevelCache: new QbIntCachePool());

        $coldPages = $this->collectChunkNames($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable(), 2);

        $this->assertSame([['P1', 'P2'], ['P3', 'P4'], ['P5']], $coldPages);
        $this->assertSame(3, $logger->selectCount, 'A cold chunked iteration reads each page from the database once.');

        $em->clear();
        $warmPages = $this->collectChunkNames($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable(), 2);

        $this->assertSame([['P1', 'P2'], ['P3', 'P4'], ['P5']], $warmPages);
        $this->assertSame(3, $logger->selectCount, 'A warm chunked iteration must serve every page from cache.');
    }

    #[DataProvider('databaseProvider')]
    public function testRowInsertedBetweenChunkIterationsIsNeitherDuplicatedNorSkippedNorServedStale(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName);
        $em = new EntityManager($connection, secondLevelCache: new QbIntCachePool());

        // Warm every page so a stale cached last page ([P5]) would be available to serve.
        $this->collectChunkNames($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable(), 2);
        $em->clear();

        $pages = [];
        foreach ($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable()->chunk(2) as $batch) {
            $pages[] = array_map(static fn (QbIntProduct $product): string => $product->name, $batch);

            if (count($pages) === 1) {
                $inserted = new QbIntProduct();
                $inserted->name = 'P6';
                $em->persist($inserted);
                $em->flush();
            }
        }

        $this->assertSame(
            [['P1', 'P2'], ['P3', 'P4'], ['P5', 'P6']],
            $pages,
            'Pages after a mid-iteration insert must be re-read under the new table generation, not served from the pre-insert cache.'
        );
    }

    #[DataProvider('databaseProvider')]
    public function testRowInsertedBetweenChunkIterationsOnALastFullPageYieldsAnExtraPage(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName);
        $em = new EntityManager($connection, secondLevelCache: new QbIntCachePool());

        // Five rows in pages of five: the warm cache holds a full page 1 and an empty page 2.
        $this->collectChunkNames($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable(), 5);
        $em->clear();

        $pages = [];
        foreach ($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable()->chunk(5) as $batch) {
            $pages[] = array_map(static fn (QbIntProduct $product): string => $product->name, $batch);

            if (count($pages) === 1) {
                $inserted = new QbIntProduct();
                $inserted->name = 'P6';
                $em->persist($inserted);
                $em->flush();
            }
        }

        $this->assertSame(
            [['P1', 'P2', 'P3', 'P4', 'P5'], ['P6']],
            $pages,
            'A cached empty trailing page must not end the iteration early after an insert.'
        );
    }

    #[DataProvider('databaseProvider')]
    public function testDisablingAFilterPerQueryNeverServesTheOtherFilterStatesCachedResult(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName, softDeleteable: true);
        $logger = $this->attachLogger($connection);
        $em = new EntityManager($connection, secondLevelCache: new QbIntCachePool());
        $em->getFilters()->add('soft_delete', new SoftDeleteFilter());

        $filtered = $this->collectNames($em->createQueryBuilder(QbIntSoftDeleteProduct::class)->orderBy('id')->cacheable()->getResult());
        $em->clear();
        $unfiltered = $this->collectNames(
            $em->createQueryBuilder(QbIntSoftDeleteProduct::class)->withoutFilter('soft_delete')->orderBy('id')->cacheable()->getResult()
        );
        $em->clear();
        $filteredAgain = $this->collectNames($em->createQueryBuilder(QbIntSoftDeleteProduct::class)->orderBy('id')->cacheable()->getResult());
        $em->clear();
        $unfilteredAgain = $this->collectNames(
            $em->createQueryBuilder(QbIntSoftDeleteProduct::class)->withoutFilter('soft_delete')->orderBy('id')->cacheable()->getResult()
        );

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $filtered);
        $this->assertSame(['P1', 'P2', 'P3', 'P4', 'P5'], $unfiltered, 'Disabling the filter must not hit the filtered query\'s cache entry.');
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $filteredAgain, 'Re-enabling the filter must not hit the unfiltered query\'s cache entry.');
        $this->assertSame(['P1', 'P2', 'P3', 'P4', 'P5'], $unfilteredAgain);
        $this->assertSame(2, $logger->selectCount, 'Each filter state keeps its own cache entry, so the repeats are cache hits.');
    }

    #[DataProvider('databaseProvider')]
    public function testTogglingAFilterOnTheEntityManagerNeverServesTheOtherFilterStatesCachedResult(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName, softDeleteable: true);
        $em = new EntityManager($connection, secondLevelCache: new QbIntCachePool());
        $em->getFilters()->add('soft_delete', new SoftDeleteFilter(), enabledByDefault: false);

        $unfiltered = $this->collectNames($em->createQueryBuilder(QbIntSoftDeleteProduct::class)->orderBy('id')->cacheable()->getResult());
        $em->clear();
        $em->getFilters()->enable('soft_delete');
        $filtered = $this->collectNames($em->createQueryBuilder(QbIntSoftDeleteProduct::class)->orderBy('id')->cacheable()->getResult());

        $this->assertSame(['P1', 'P2', 'P3', 'P4', 'P5'], $unfiltered);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], $filtered, 'Enabling the filter must not hit the unfiltered query\'s cache entry.');
    }

    #[DataProvider('databaseProvider')]
    public function testChunkingWithAndWithoutAFilterKeepsSeparatePageCaches(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName, softDeleteable: true);
        $em = new EntityManager($connection, secondLevelCache: new QbIntCachePool());
        $em->getFilters()->add('soft_delete', new SoftDeleteFilter());

        $filtered = $this->collectChunkNames($em->createQueryBuilder(QbIntSoftDeleteProduct::class)->orderBy('id')->cacheable(), 2);
        $em->clear();
        $unfiltered = $this->collectChunkNames(
            $em->createQueryBuilder(QbIntSoftDeleteProduct::class)->withoutFilter('soft_delete')->orderBy('id')->cacheable(),
            2
        );
        $em->clear();
        $filteredAgain = $this->collectChunkNames($em->createQueryBuilder(QbIntSoftDeleteProduct::class)->orderBy('id')->cacheable(), 2);

        $this->assertSame([['P1', 'P2'], ['P3', 'P4']], $filtered);
        $this->assertSame([['P1', 'P2'], ['P3', 'P4'], ['P5']], $unfiltered);
        $this->assertSame([['P1', 'P2'], ['P3', 'P4']], $filteredAgain);
    }

    #[DataProvider('databaseProvider')]
    public function testCacheBackendFailingMidChunkOverAWarmCacheFallsThroughToTheDatabase(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName);
        $cache = new QbIntCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $this->collectChunkNames($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable(), 2);
        $em->clear();
        $logger = $this->attachLogger($connection);

        $pages = [];
        foreach ($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable()->chunk(2) as $batch) {
            $pages[] = $this->collectNames($batch);
            $cache->down = true;
        }

        $this->assertSame([['P1', 'P2'], ['P3', 'P4'], ['P5']], $pages);
        $this->assertSame(2, $logger->selectCount, 'Only the pages after the backend failed are read from the database.');
    }

    #[DataProvider('databaseProvider')]
    public function testCacheBackendFailingMidChunkOverAColdCacheFallsThroughToTheDatabase(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName);
        $cache = new QbIntCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $pages = [];
        foreach ($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable()->chunk(2) as $batch) {
            $pages[] = $this->collectNames($batch);
            $cache->down = true;
        }

        $this->assertSame([['P1', 'P2'], ['P3', 'P4'], ['P5']], $pages);
    }

    #[DataProvider('databaseProvider')]
    public function testFlushingAnInsertMidChunkWhileTheCacheBackendIsDownStillYieldsTheNewRow(string $databaseName): void
    {
        $connection = $this->prepareProducts($databaseName);
        $cache = new QbIntCachePool();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $this->collectChunkNames($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable(), 2);
        $em->clear();

        $pages = [];
        foreach ($em->createQueryBuilder(QbIntProduct::class)->orderBy('id')->cacheable()->chunk(2) as $batch) {
            $pages[] = $this->collectNames($batch);

            if (count($pages) === 1) {
                $cache->down = true;
                $inserted = new QbIntProduct();
                $inserted->name = 'P6';
                $em->persist($inserted);
                $em->flush();
            }
        }

        $this->assertSame([['P1', 'P2'], ['P3', 'P4'], ['P5', 'P6']], $pages);
    }

    /**
     * @param list<object> $products
     * @return list<string>
     */
    private function collectNames(array $products): array
    {
        return array_map(static fn (object $product): string => $product->name, $products);
    }

    /**
     * @return list<list<string>>
     */
    private function collectChunkNames(QueryBuilder $queryBuilder, int $size): array
    {
        $pages = [];
        foreach ($queryBuilder->chunk($size) as $batch) {
            $pages[] = array_map(static fn (object $product): string => $product->name, $batch);
        }

        return $pages;
    }

    private function prepareProducts(string $databaseName, bool $softDeleteable = false): Connection
    {
        $connection = $this->getConnection($databaseName);
        $deletedAtType = $databaseName === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';
        $deletedAtColumn = $softDeleteable ? ", deleted_at {$deletedAtType} NULL" : '';

        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_int_products CASCADE');
            $connection->executeQuery("CREATE TABLE qb_int_products (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL{$deletedAtColumn})");
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS qb_int_products');
            $connection->executeQuery("CREATE TABLE qb_int_products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL{$deletedAtColumn})");
        }
        $this->setCurrentDatabase($connection, $databaseName);

        foreach (self::PRODUCT_NAMES as $name) {
            $connection->executeQuery('INSERT INTO qb_int_products (name) VALUES (?)', [$name]);
        }

        if ($softDeleteable) {
            $connection->executeQuery('UPDATE qb_int_products SET deleted_at = CURRENT_TIMESTAMP WHERE name = ?', ['P5']);
        }

        return $connection;
    }

    private function attachLogger(Connection $connection): QbIntSelectCountingLogger
    {
        $logger = new QbIntSelectCountingLogger();
        $property = new \ReflectionProperty(Connection::class, 'queryLogger');
        $property->setValue($connection, $logger);

        return $logger;
    }
}
