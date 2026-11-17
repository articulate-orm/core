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

    public function getTableGenerations(array $tableNames): ?array
    {
        return null;
    }

    public function incrementTableGenerations(array $tableNames): void
    {
    }
}
