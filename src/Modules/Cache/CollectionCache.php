<?php

namespace Articulate\Modules\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6-backed storage for the collection (query-region) cache.
 *
 * Key prefixes:
 *  - `l2q:`    id-lists matched by a `cacheable()` query (this ticket)
 *  - `l2qidx:` reverse-index entries (`tableName:normalizedId -> [cacheKey,...]`),
 *              reserved for the exact-invalidation ticket.
 *
 * Distinct from SecondLevelCache::generateKey()'s plain per-entity hash —
 * this module never stores row data, only id-lists.
 */
class CollectionCache implements CollectionCacheInterface {
    private const ID_LIST_PREFIX = 'l2q:';

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
}
