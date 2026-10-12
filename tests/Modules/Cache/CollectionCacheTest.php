<?php

namespace Articulate\Tests\Modules\Cache;

use Articulate\Modules\Cache\CollectionCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class CacheItem implements CacheItemInterface {
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
}
