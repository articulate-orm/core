<?php

namespace Articulate\Modules\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6-backed storage for the collection (query-region) cache.
 *
 * Key prefixes:
 *  - `l2q_`    id-lists matched by a `cacheable()` query
 *  - `l2qidx_` reverse-index entries (hash of namespace + table + normalized id -> [cacheKey,...]),
 *              used for exact write-time invalidation + read-time cross-validation.
 *
 * Prefixes avoid the PSR-6 reserved characters {}()/\@: so real pools accept the keys.
 *
 * Distinct from SecondLevelCache::generateKey()'s plain per-entity hash —
 * this module never stores row data, only id-lists and reverse-index entries.
 */
class CollectionCache implements CollectionCacheInterface {
    private const ID_LIST_PREFIX = 'l2q_';

    private const REVERSE_INDEX_PREFIX = 'l2qidx_';

    /**
     * Fixed grace buffer added on top of the query-cache entry's own TTL so
     * the reverse-index entry always outlives the entry it protects (ADR-0002).
     */
    public const REVERSE_INDEX_GRACE_SECONDS = 300;

    private const ADD_ATTEMPTS = 3;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $namespace = '',
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
            $now = time();

            // PSR-6 has no atomic append, so concurrent writers can overwrite each
            // other's read-modify-write. Re-read after saving and retry (bounded) if
            // our entry was lost; the residual window is documented in ADR-0002.
            for ($attempt = 0; $attempt < self::ADD_ATTEMPTS; ++$attempt) {
                $item = $this->pool->getItem($key);
                $entries = $this->liveEntries($item->isHit() ? $item->get() : null, $now);
                $entries[$cacheKey] = max($entries[$cacheKey] ?? 0, $now + $ttl);

                // Lifetime follows the longest-lived query entry the row is part of, so a
                // short-TTL query can never shorten the index protecting a long-TTL one.
                $item->set($entries)->expiresAfter(max($entries) - $now + self::REVERSE_INDEX_GRACE_SECONDS);
                $this->pool->save($item);

                $check = $this->pool->getItem($key);
                if ($check->isHit() && isset($this->liveEntries($check->get(), $now)[$cacheKey])) {
                    return;
                }
            }
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    public function getReverseIndexCacheKeys(string $tableName, mixed $id): ?array
    {
        try {
            $item = $this->pool->getItem($this->reverseIndexKey($tableName, $id));
            if (!$item->isHit()) {
                return null;
            }

            // Expired query entries are kept out of the result; evicting them is a no-op anyway.
            return array_keys($this->liveEntries($item->get(), time()));
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
        return self::REVERSE_INDEX_PREFIX . hash('sha256', $this->namespace . "\0" . $tableName . ':' . IdNormalizer::normalize($id));
    }

    /**
     * Reverse-index payload: map of query cache key => epoch second at which that
     * query entry expires. Anything malformed or already expired is dropped.
     *
     * @return array<string, int>
     */
    private function liveEntries(mixed $stored, int $now): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $live = [];
        foreach ($stored as $cacheKey => $expiresAt) {
            if (is_string($cacheKey) && is_int($expiresAt) && $expiresAt > $now) {
                $live[$cacheKey] = $expiresAt;
            }
        }

        return $live;
    }
}
