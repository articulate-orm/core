<?php

namespace Articulate\Modules\Cache;

/**
 * Query-region cache: caches the ordered list of primary keys matched by a
 * QueryBuilder query (not the row data itself), plus the reverse index that
 * maps a changed row back to every cached query that referenced it. See
 * ADR-0002.
 */
interface CollectionCacheInterface {
    /**
     * @return list<mixed>|null null on cache miss
     */
    public function getIdList(string $key): ?array;

    /**
     * @param list<mixed> $ids
     */
    public function setIdList(string $key, array $ids, int $ttl): void;

    /**
     * Evict a single cached id-list entry by its cache key — used at
     * flush-time invalidation to remove exactly the query-cache entries a
     * reverse-index lookup named, without touching any other query's entry.
     */
    public function evictIdList(string $key): void;

    /**
     * Record that the id-list cached under $cacheKey includes the row
     * identified by ($tableName, $id), so a later write to that row can
     * evict exactly that cache entry. $ttl is the TTL of the query-cache
     * entry itself; the reverse-index entry's own TTL is always set higher
     * (see REVERSE_INDEX_GRACE_SECONDS) so it outlives it. $id is
     * normalized internally the same way SecondLevelCache normalizes ids,
     * so callers never need to pre-normalize.
     */
    public function addToReverseIndex(string $tableName, mixed $id, string $cacheKey, int $ttl): void;

    /**
     * @return list<string>|null null when no reverse-index entry exists for this row
     */
    public function getReverseIndexCacheKeys(string $tableName, mixed $id): ?array;

    public function evictReverseIndex(string $tableName, mixed $id): void;

    /**
     * Batch existence check (single round trip) for the read-time
     * cross-validation protocol: given every id a cached list claims to
     * contain, return the subset of ids whose reverse-index entry is
     * missing (either never written or evicted early by the PSR-6 backend).
     * An empty array means every id's reverse-index entry is intact.
     *
     * @param list<mixed> $ids
     * @return list<mixed>
     */
    public function findMissingReverseIndexIds(string $tableName, array $ids): array;
}
