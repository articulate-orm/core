<?php

namespace Articulate\Modules\Cache;

/**
 * Query-region cache: caches the ordered list of primary keys matched by a
 * QueryBuilder query (not the row data itself). See ADR-0002.
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
}
