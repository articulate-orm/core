<?php

namespace Articulate\Modules\Cache;

/**
 * Query-region cache: caches the ordered list of primary keys matched by a
 * QueryBuilder query (not the row data itself), plus one table generation
 * counter per table. A cached list's key folds in the generations of the
 * tables it read, so a unit-of-work write that bumps any of them makes the
 * list stop matching. See CONTEXT.md (table generation).
 */
interface CollectionCacheInterface {
    public const DEFAULT_TTL = 3600;

    /**
     * A cacheable(name:) query gets a generation counter of its own under this
     * prefix. ':' is not legal in a table identifier, so it never collides
     * with a real table's counter.
     */
    public const NAME_PREFIX = 'name:';

    /**
     * @return list<mixed>|null null on cache miss
     */
    public function getIdList(string $key): ?array;

    /**
     * @param list<mixed> $ids
     */
    public function setIdList(string $key, array $ids, int $ttl): void;

    /**
     * Reads every table's generation in one batched round trip. A counter
     * that does not exist yet is seeded with a random value, never 0, so a
     * dropped counter cannot restart at a generation older lists were keyed
     * against.
     *
     * @param list<string> $tableNames
     * @return array<string, int>|null null when any counter cannot be read;
     *                                 the caller must then skip caching, since
     *                                 a list stored without a real generation
     *                                 could never be invalidated by a write
     */
    public function getTableGenerations(array $tableNames): ?array;

    /**
     * @param list<string> $tableNames
     */
    public function incrementTableGenerations(array $tableNames): void;
}
