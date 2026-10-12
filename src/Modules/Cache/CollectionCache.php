<?php

namespace Articulate\Modules\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6-backed storage for the collection (query-region) cache.
 *
 * Key prefixes:
 *  - `l2q:`    id-lists matched by a `cacheable()` query
 *  - `l2qidx:` reverse-index entries (`tableName:normalizedId -> [cacheKey,...]`),
 *              used for exact write-time invalidation + read-time cross-validation.
 *
 * Distinct from SecondLevelCache::generateKey()'s plain per-entity hash —
 * this module never stores row data, only id-lists and reverse-index entries.
 */
class CollectionCache implements CollectionCacheInterface {
    private const ID_LIST_PREFIX = 'l2q:';

    private const REVERSE_INDEX_PREFIX = 'l2qidx:';

    /**
     * Fixed grace buffer added on top of the query-cache entry's own TTL so
     * the reverse-index entry always outlives the entry it protects (ADR-0002).
     */
    public const REVERSE_INDEX_GRACE_SECONDS = 300;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function getIdList(string $key): ?array
    {
        try {
            $item = $this->pool->getItem(self::ID_LIST_PREFIX . $key);

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function setIdList(string $key, array $ids, int $ttl): void
    {
        try {
            $item = $this->pool->getItem(self::ID_LIST_PREFIX . $key);
            $item->set($ids)->expiresAfter($ttl);
            $this->pool->save($item);
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    public function evictIdList(string $key): void
    {
        try {
            $this->pool->deleteItem(self::ID_LIST_PREFIX . $key);
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    public function addToReverseIndex(string $tableName, mixed $id, string $cacheKey, int $ttl): void
    {
        try {
            $key = $this->reverseIndexKey($tableName, $id);
            $item = $this->pool->getItem($key);
            $existing = $item->isHit() ? $item->get() : [];
            if (!is_array($existing)) {
                $existing = [];
            }

            if (!in_array($cacheKey, $existing, true)) {
                $existing[] = $cacheKey;
            }

            $item->set($existing)->expiresAfter($ttl + self::REVERSE_INDEX_GRACE_SECONDS);
            $this->pool->save($item);
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    public function getReverseIndexCacheKeys(string $tableName, mixed $id): ?array
    {
        try {
            $item = $this->pool->getItem($this->reverseIndexKey($tableName, $id));

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function evictReverseIndex(string $tableName, mixed $id): void
    {
        try {
            $this->pool->deleteItem($this->reverseIndexKey($tableName, $id));
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    public function findMissingReverseIndexIds(string $tableName, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        try {
            $keyToId = [];
            foreach ($ids as $id) {
                $keyToId[$this->reverseIndexKey($tableName, $id)] = $id;
            }

            $items = $this->pool->getItems(array_keys($keyToId));

            $missing = [];
            foreach ($items as $key => $item) {
                if (!$item->isHit()) {
                    $missing[] = $keyToId[$key];
                }
            }

            return $missing;
        } catch (\Throwable) {
            // Cache failure is treated as every id missing — the caller
            // discards the cached list and requeries, never serving stale data.
            return $ids;
        }
    }

    private function reverseIndexKey(string $tableName, mixed $id): string
    {
        return self::REVERSE_INDEX_PREFIX . $tableName . ':' . IdNormalizer::normalize($id);
    }
}
