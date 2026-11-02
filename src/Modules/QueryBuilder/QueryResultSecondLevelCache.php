<?php

namespace Articulate\Modules\QueryBuilder;

use Articulate\Modules\Cache\CollectionCacheInterface;

/**
 * Decides hit/miss for a `cacheable()` query and builds/stores its id-list
 * cache key. Entity resolution on a hit is QueryBuilder's job (it alone has
 * access to the managed-entity-store / SecondLevelCache bridge) — this
 * collaborator only owns the collection-cache key/TTL mechanics. See ADR-0002.
 */
class QueryResultSecondLevelCache {
    private bool $enabled = false;

    private ?int $ttl = null;

    public function __construct(
        private readonly CollectionCacheInterface $collectionCache,
        private readonly string $namespace = '',
    ) {
    }

    public function enable(?int $ttl): void
    {
        $this->enabled = true;
        $this->ttl = $ttl;
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->ttl = null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<mixed>|null null on cache miss or when not enabled
     */
    public function getCachedIds(string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->collectionCache->getIdList($key);
    }

    /**
     * @param list<mixed> $ids
     */
    public function storeIds(string $key, array $ids, int $defaultTtl): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->collectionCache->setIdList($key, $ids, $this->ttl ?? $defaultTtl);
    }

    /**
     * Same as storeIds(), plus a reverse-index entry per id so a later
     * write to any of those rows can evict exactly this cache entry. See
     * ADR-0002. No-op when not enabled, matching storeIds().
     *
     * @param list<mixed> $ids
     */
    public function storeIdsWithReverseIndex(string $key, array $ids, int $defaultTtl, string $tableName): void
    {
        if (!$this->enabled) {
            return;
        }

        $ttl = $this->ttl ?? $defaultTtl;
        $this->collectionCache->setIdList($key, $ids, $ttl);

        foreach ($ids as $id) {
            $this->collectionCache->addToReverseIndex($tableName, $id, $key, $ttl);
        }
    }

    /**
     * Read-time cross-validation (ADR-0002, "PSR-6 eviction opacity"
     * protocol): on a list-cache hit, batch-check that the reverse-index
     * entry still exists for every id in the hit list. If any is missing
     * (the backend evicted it early, independent of TTL), the ENTIRE cached
     * list is discarded — not partially repaired — so the caller requeries,
     * which naturally rewrites the list + reverse index.
     *
     * @return list<mixed>|null null on cache miss, not enabled, or a
     *                           detected reverse-index inconsistency
     */
    public function getCachedIdsWithCrossValidation(string $key, string $tableName): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $ids = $this->collectionCache->getIdList($key);
        if ($ids === null) {
            return null;
        }

        $missing = $this->collectionCache->findMissingReverseIndexIds($tableName, $ids);
        if (!empty($missing)) {
            return null;
        }

        return $ids;
    }

    /**
     * Returns null when the parameters cannot be reduced to an unambiguous
     * string (unserializable objects, resources) — the caller must then skip
     * caching rather than risk two different queries sharing a key.
     *
     * @param list<mixed> $params
     */
    public function buildCacheKey(?string $entityClass, string $sql, array $params): ?string
    {
        $normalized = $this->normalizeParams($params);
        if ($normalized === null) {
            return null;
        }

        return 'cq_' . hash('sha256', serialize([$this->namespace, $entityClass ?? '', $sql, $normalized]));
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|null
     */
    private function normalizeParams(array $params): ?array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[$key] = $this->normalizeParam($value);
            if ($normalized[$key] === null && $value !== null) {
                return null;
            }
        }

        return $normalized;
    }

    private function normalizeParam(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->normalizeParams($value);
        }

        if ($value instanceof \Stringable) {
            return ['o', $value::class, (string) $value];
        }

        if (is_object($value)) {
            try {
                return ['o', $value::class, serialize($value)];
            } catch (\Throwable) {
                return null;
            }
        }

        return is_resource($value) ? null : $value;
    }
}
