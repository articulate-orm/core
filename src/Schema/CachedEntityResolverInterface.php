<?php

namespace Articulate\Schema;

/**
 * Resolves a cached primary key back into a managed entity without hitting
 * the database — checked by QueryBuilder on a `cacheable()` query's cache
 * hit. Implemented at the EntityManager layer (which alone can reach the
 * identity map and the per-entity SecondLevelCache); QueryBuilder only knows
 * this Schema-layer contract, keeping the Deptrac dependency direction intact.
 *
 * A null return means the id could not be resolved without a database read —
 * the caller must treat the whole cached list as a miss, not patch it
 * partially (same whole-list-discard rule as ADR-0002's read-time
 * cross-validation).
 */
interface CachedEntityResolverInterface {
    public function tryResolve(string $class, mixed $id): ?object;

    /**
     * Writes a freshly-fetched row into the same per-entity second-level
     * cache `find()` populates, so a later cacheable() cache hit can resolve
     * this id without a database read. No-op when no L2 pool is configured.
     *
     * @param array<string, mixed> $rawData
     */
    public function cacheResolvedRow(string $class, mixed $id, array $rawData): void;
}
