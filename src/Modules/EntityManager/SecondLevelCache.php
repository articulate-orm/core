<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Modules\Cache\IdNormalizer;
use Psr\Cache\CacheItemPoolInterface;

class SecondLevelCache {
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly int $defaultTtl = 3600,
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
        return hash('sha256', $class . ':' . $this->normalizeId($id));
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
