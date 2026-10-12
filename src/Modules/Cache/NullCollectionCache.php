<?php

namespace Articulate\Modules\Cache;

/**
 * Null Object — always a miss, writes are no-ops. Lets QueryBuilder's
 * collaborator take a non-nullable CollectionCacheInterface with zero `?->`
 * branching when no second-level cache pool is configured.
 */
class NullCollectionCache implements CollectionCacheInterface {
    public function getIdList(string $key): ?array
    {
        return null;
    }

    public function setIdList(string $key, array $ids, int $ttl): void
    {
    }

    public function evictIdList(string $key): void
    {
    }

    public function addToReverseIndex(string $tableName, mixed $id, string $cacheKey, int $ttl): void
    {
    }

    public function getReverseIndexCacheKeys(string $tableName, mixed $id): ?array
    {
        return null;
    }

    public function evictReverseIndex(string $tableName, mixed $id): void
    {
    }

    public function findMissingReverseIndexIds(string $tableName, array $ids): array
    {
        return [];
    }
}
