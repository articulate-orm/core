<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\SecondLevelCache;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class L2CacheItem implements CacheItemInterface {
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
        if ($this->expiration !== null && $this->expiration < time()) {
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
            $this->expiration = time() + $time;
        } else {
            $this->expiration = (new \DateTime())->add($time)->getTimestamp();
        }

        return $this;
    }
}

class L2ArrayCache implements CacheItemPoolInterface {
    private array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new L2CacheItem($key);
    }

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

#[Entity(tableName: 'l2_cache_users')]
class L2CacheUser {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'l2_cache_users')]
class L2CacheUserSummary {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

class FailingCache extends L2ArrayCache {
    public function getItem(string $key): never
    {
        throw new \RuntimeException('Cache backend failure');
    }

    public function deleteItem(string $key): never
    {
        throw new \RuntimeException('Cache backend failure');
    }
}

class SecondLevelCacheTest extends DatabaseTestCase {
    #[DataProvider('databaseProvider')]
    public function testFindPopulatesCache(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Alice')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        $em->find(L2CacheUser::class, $id);

        $key = (new SecondLevelCache($cache, 3600, $connection->getCacheNamespace()))->generateKey(L2CacheUser::class, $id);
        $this->assertTrue($cache->hasItem($key));
    }

    #[DataProvider('databaseProvider')]
    public function testCacheHitSkipsDatabase(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Bob')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        // First find: DB hit, L2 cache populated
        $em->find(L2CacheUser::class, $id);

        // Mutate DB directly — bypasses EntityManager
        $connection->executeQuery("UPDATE l2_cache_users SET name = 'Changed' WHERE id = ?", [$id]);

        // Clear identity map so find() does not return the in-memory instance
        $em->clear();

        // Second find: L2 cache hit — should return cached (pre-mutation) value
        $entity = $em->find(L2CacheUser::class, $id);

        $this->assertNotNull($entity);
        $this->assertSame('Bob', $entity->name);
    }

    #[DataProvider('databaseProvider')]
    public function testCacheInvalidatedOnUpdate(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Carol')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        // Load entity → L2 cache populated
        $entity = $em->find(L2CacheUser::class, $id);
        $this->assertSame('Carol', $entity->name);

        // Modify and flush → should invalidate L2 cache entry
        $entity->name = 'Carol Updated';
        $em->persist($entity);
        $em->flush();

        // Clear identity map
        $em->clear();

        // Next find must return updated value (not stale cache)
        $fresh = $em->find(L2CacheUser::class, $id);
        $this->assertNotNull($fresh);
        $this->assertSame('Carol Updated', $fresh->name);
    }

    #[DataProvider('databaseProvider')]
    public function testCacheInvalidatedOnDelete(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Dave')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        // Load entity → L2 cache populated
        $entity = $em->find(L2CacheUser::class, $id);
        $this->assertNotNull($entity);

        // Delete and flush → should evict L2 cache entry
        $em->remove($entity);
        $em->flush();

        $em->clear();

        // Next find must return null (not stale cached data)
        $result = $em->find(L2CacheUser::class, $id);
        $this->assertNull($result);
    }

    #[DataProvider('databaseProvider')]
    public function testNoCacheConfiguredWorksNormally(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Eve')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $em = new EntityManager($connection);

        $entity = $em->find(L2CacheUser::class, $id);

        $this->assertNotNull($entity);
        $this->assertSame('Eve', $entity->name);
    }

    #[DataProvider('databaseProvider')]
    public function testCacheBackendFailureIsGraceful(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Frank')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $em = new EntityManager($connection, secondLevelCache: new FailingCache());

        // Must not throw even though cache backend always throws
        $entity = $em->find(L2CacheUser::class, $id);

        $this->assertNotNull($entity);
        $this->assertSame('Frank', $entity->name);
    }

    #[DataProvider('databaseProvider')]
    public function testCustomTtlIsApplied(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Grace')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache, secondLevelCacheTtl: 1);

        $em->find(L2CacheUser::class, $id);

        $key = (new SecondLevelCache($cache, 3600, $connection->getCacheNamespace()))->generateKey(L2CacheUser::class, $id);
        $this->assertTrue($cache->hasItem($key));

        sleep(2);

        // After TTL expiry the item should no longer be a hit
        $this->assertFalse($cache->hasItem($key));
    }

    #[DataProvider('databaseProvider')]
    public function testCacheInvalidatedForSiblingClassOnUpdate(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Helen')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        // Populate L2 cache for both entity classes
        $em->find(L2CacheUser::class, $id);
        $em->clear();
        $em->find(L2CacheUserSummary::class, $id);
        $em->clear();

        // Re-load and update via L2CacheUser
        $user = $em->find(L2CacheUser::class, $id);
        $user->name = 'Helen Updated';
        $em->persist($user);
        $em->flush();
        $em->clear();

        $l2 = new SecondLevelCache($cache, 3600, $connection->getCacheNamespace());
        $this->assertFalse($cache->hasItem($l2->generateKey(L2CacheUser::class, $id)), 'L2CacheUser cache entry should be evicted');
        $this->assertFalse($cache->hasItem($l2->generateKey(L2CacheUserSummary::class, $id)), 'L2CacheUserSummary cache entry should be evicted alongside sibling');
    }

    #[DataProvider('databaseProvider')]
    public function testCacheInvalidatedForSiblingClassOnDelete(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $connection = $this->getCurrentConnection();
        $this->createUserTable($connection, $databaseName);

        $connection->executeQuery("INSERT INTO l2_cache_users (name) VALUES ('Ivan')");
        $id = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'l2_cache_users_id_seq' : null);

        $cache = new L2ArrayCache();
        $em = new EntityManager($connection, secondLevelCache: $cache);

        // Populate L2 cache for both entity classes
        $em->find(L2CacheUser::class, $id);
        $em->clear();
        $em->find(L2CacheUserSummary::class, $id);
        $em->clear();

        // Delete via L2CacheUser
        $user = $em->find(L2CacheUser::class, $id);
        $em->remove($user);
        $em->flush();
        $em->clear();

        $l2 = new SecondLevelCache($cache, 3600, $connection->getCacheNamespace());
        $this->assertFalse($cache->hasItem($l2->generateKey(L2CacheUser::class, $id)), 'L2CacheUser cache entry should be evicted');
        $this->assertFalse($cache->hasItem($l2->generateKey(L2CacheUserSummary::class, $id)), 'L2CacheUserSummary cache entry should be evicted alongside sibling');
    }

    // --- key normalization (no database needed) ------------------------------

    public function testIntAndStringIdsDoNotCollide(): void
    {
        $cache = new SecondLevelCache(new L2ArrayCache());

        $this->assertNotSame(
            $cache->generateKey(L2CacheUser::class, 1),
            $cache->generateKey(L2CacheUser::class, '1'),
        );
    }

    public function testStringableIdIsSupported(): void
    {
        $cache = new SecondLevelCache(new L2ArrayCache());

        $id = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'abc-123';
            }
        };

        $this->assertSame(
            $cache->generateKey(L2CacheUser::class, $id),
            $cache->generateKey(L2CacheUser::class, $id),
        );
    }

    public function testCompositeKeyOrderIsStable(): void
    {
        $cache = new SecondLevelCache(new L2ArrayCache());

        $this->assertSame(
            $cache->generateKey(L2CacheUser::class, ['tenant' => 4, 'id' => 7]),
            $cache->generateKey(L2CacheUser::class, ['id' => 7, 'tenant' => 4]),
        );
    }

    public function testCompositeKeyValuesContribute(): void
    {
        $cache = new SecondLevelCache(new L2ArrayCache());

        // Same keys, one differing value → must produce different cache keys
        $this->assertNotSame(
            $cache->generateKey(L2CacheUser::class, ['tenant' => 4, 'id' => 7]),
            $cache->generateKey(L2CacheUser::class, ['tenant' => 4, 'id' => 8]),
        );
    }

    public function testCompositeKeyKeysContribute(): void
    {
        $cache = new SecondLevelCache(new L2ArrayCache());

        // Same value, different key name → must produce different cache keys
        $this->assertNotSame(
            $cache->generateKey(L2CacheUser::class, ['tenant' => 1]),
            $cache->generateKey(L2CacheUser::class, ['region' => 1]),
        );
    }

    public function testNonStringableObjectIdThrows(): void
    {
        $cache = new SecondLevelCache(new L2ArrayCache());

        $this->expectException(\InvalidArgumentException::class);
        $cache->generateKey(L2CacheUser::class, new \stdClass());
    }

    // -------------------------------------------------------------------------

    private function createUserTable(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS l2_cache_users CASCADE');
            $connection->executeQuery(
                'CREATE TABLE l2_cache_users (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)'
            );
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS l2_cache_users');
            $connection->executeQuery(
                'CREATE TABLE l2_cache_users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL)'
            );
        }
    }
}
