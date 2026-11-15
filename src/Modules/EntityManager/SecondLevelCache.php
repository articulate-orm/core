<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\Cache\IdNormalizer;
use Psr\Cache\CacheItemPoolInterface;

class SecondLevelCache {
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly int $defaultTtl = CollectionCacheInterface::DEFAULT_TTL,
        private readonly string $namespace = '',
    ) {
    }

    public function get(string $class, mixed $id): ?array
    {
        try {
            $item = $this->pool->getItem($this->generateKey($class, $id));

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $class, mixed $id, array $data): void
    {
        try {
            $item = $this->pool->getItem($this->generateKey($class, $id));
            $item->set($data)->expiresAfter($this->defaultTtl);
            $this->pool->save($item);
        } catch (\Throwable) {
            // Cache failure never breaks query execution
        }
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, array<string, mixed>|null> raw data per index of $ids, null for misses
     */
    public function getMany(string $class, array $ids): array
    {
        try {
            $keys = array_map(fn (mixed $id): string => $this->generateKey($class, $id), $ids);
            $rows = [];
            foreach ($this->pool->getItems(array_values(array_unique($keys))) as $key => $item) {
                $rows[$key] = $item->isHit() ? $item->get() : null;
            }

            return array_map(fn (string $key): ?array => $rows[$key] ?? null, $keys);
        } catch (\Throwable) {
            return array_fill_keys(array_keys($ids), null);
        }
    }

    /**
     * @param list<array{0: mixed, 1: array<string, mixed>}> $idRowPairs
     */
    public function setMany(string $class, array $idRowPairs): void
    {
        try {
            foreach ($idRowPairs as [$id, $data]) {
                $item = $this->pool->getItem($this->generateKey($class, $id));
                $item->set($data)->expiresAfter($this->defaultTtl);
                $this->pool->saveDeferred($item);
            }

            $this->pool->commit();
        } catch (\Throwable) {
            // Cache failure never breaks query execution
        }
    }

    public function evict(string $class, mixed $id): void
    {
        try {
            $this->pool->deleteItem($this->generateKey($class, $id));
        } catch (\Throwable) {
            // Cache failure never breaks query execution
        }
    }

    public function generateKey(string $class, mixed $id): string
    {
        return hash('sha256', $this->namespace . $class . ':' . $this->normalizeId($id));
    }

    /**
     * Delegates to the shared IdNormalizer so reverse-index keys built in the
     * Cache module normalize identifiers identically to this per-entity region.
     */
    private function normalizeId(mixed $id): string
    {
        return IdNormalizer::normalize($id);
    }
}
