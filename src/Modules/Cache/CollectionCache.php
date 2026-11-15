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
        $this->addIdsToReverseIndex($tableName, [$id], $cacheKey, $ttl);
    }

    public function addIdsToReverseIndex(string $tableName, array $ids, string $cacheKey, int $ttl): void
    {
        try {
            $pending = [];
            foreach ($ids as $id) {
                $pending[$this->reverseIndexKey($tableName, $id)] = true;
            }

            // PSR-6 has no atomic append, so concurrent writers can overwrite each
            // other's read-modify-write. Each round is batched (one getItems, one
            // commit, one verification read); only keys whose entry was lost are
            // retried. The residual window is documented in ADR-0002.
            for ($attempt = 0; $attempt < self::ADD_ATTEMPTS && $pending !== []; $attempt++) {
                $now = time();
                foreach ($this->pool->getItems(array_keys($pending)) as $key => $item) {
                    $entries = $this->liveEntries($item->isHit() ? $item->get() : null, $now);
                    $entries[$cacheKey] = max($entries[$cacheKey] ?? 0, $now + $ttl);

                    // Lifetime follows the longest-lived query entry the row is part of, so a
                    // short-TTL query can never shorten the index protecting a long-TTL one.
                    $item->set($entries)->expiresAfter(max($entries) - $now + self::REVERSE_INDEX_GRACE_SECONDS);
                    $this->pool->saveDeferred($item);
                }
                $this->pool->commit();

                foreach ($this->pool->getItems(array_keys($pending)) as $key => $item) {
                    if ($item->isHit() && isset($this->liveEntries($item->get(), $now)[$cacheKey])) {
                        unset($pending[$key]);
                    }
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
            return array_map('strval', array_keys($this->liveEntries($item->get(), time())));
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

    public function findMissingReverseIndexIds(string $tableName, array $ids, ?string $cacheKey = null): array
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
                // Missing, or present but no longer naming this list (lost update / old payload format).
                if (!$item->isHit() || ($cacheKey !== null && !isset($this->liveEntries($item->get(), time())[$cacheKey]))) {
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
            if (is_int($expiresAt) && $expiresAt > $now) {
                $live[(string) $cacheKey] = $expiresAt;
            }
        }

        return $live;
    }
}
