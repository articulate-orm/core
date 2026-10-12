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
     * @param list<mixed> $params
     */
    public function buildCacheKey(?string $entityClass, string $sql, array $params): string
    {
        return 'cq_' . hash('sha256', ($entityClass ?? '') . '|' . $sql . '|' . json_encode($params));
    }
}
