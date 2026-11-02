<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Modules\QueryBuilder\SqlStatementCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class SqlStatementCacheSpyItem implements CacheItemInterface {
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

class SqlStatementCacheSpyPool implements CacheItemPoolInterface {
    /** @var array<string, SqlStatementCacheSpyItem> */
    private array $items = [];

    public int $saveCallCount = 0;

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new SqlStatementCacheSpyItem($key);
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

class SqlStatementCacheTest extends TestCase {
    /**
     * Mutant: Identical on `$this->cachePool === null` → `$this->cachePool !== null` in get()
     * (SqlStatementCache.php:21). With the condition flipped, a cache configured WITH a pool
     * would immediately short-circuit to null on every get(), and a cache WITHOUT a pool
     * would try to call getItem() on null and fatal.
     */
    public function testGetReturnsNullWhenNoCachePoolConfigured(): void
    {
        $cache = new SqlStatementCache(null);

        $this->assertNull($cache->get('any-key'));
    }

    public function testGetReturnsStoredValueWhenCachePoolConfigured(): void
    {
        $pool = new SqlStatementCacheSpyPool();
        $cache = new SqlStatementCache($pool);

        $cache->set('key1', 'SELECT * FROM users');

        $this->assertSame('SELECT * FROM users', $cache->get('key1'));
    }

    /**
     * Mutant: MethodCallRemoval on `$this->cachePool->save($item)` in set()
     * (SqlStatementCache.php:43). Without persisting the item to the pool, the cache would
     * never actually retain anything across a fresh getItem() call on implementations that
     * don't share item identity in-process.
     */
    public function testSetPersistsSqlToCachePool(): void
    {
        $pool = new SqlStatementCacheSpyPool();
        $cache = new SqlStatementCache($pool);

        $cache->set('key1', 'SELECT 1');

        $this->assertSame(1, $pool->saveCallCount);
        $this->assertTrue($pool->hasItem('key1'));
    }

    public function testIsEnabledReflectsCachePoolPresence(): void
    {
        $this->assertFalse((new SqlStatementCache(null))->isEnabled());
        $this->assertTrue((new SqlStatementCache(new SqlStatementCacheSpyPool()))->isEnabled());
    }
}
