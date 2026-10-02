---
title: "ADR 0002: Second-Level Cache for Collection/List Reads"
description: Architecture decision record — extending the second-level cache to QueryBuilder::getResult()/chunk() via a reverse-indexed query-region cache.
sidebar:
  order: 2
---

## Context

`SecondLevelCache` + `EntityCacheCoordinator` cache single entities by `(class, primaryKey)`, wired only
into `EntityManager::find()`. `QueryBuilder::getResult()` and `QueryBuilder::chunk()` always hit the
database — list/batch reads bypass the L2 cache entirely. Verified empirically on 2026-10-01 (dual-DB
spy on `CacheItemPoolInterface::getItem()`): `find()` calls it twice, `getResult()`/`chunk()` call it
zero times on an identically-configured `EntityManager`.

Doctrine ORM has a comparable **query region**: it caches identifier lists per DQL query (opt-in via
`Query::setCacheable(true)`), separate from its per-entity region, and invalidates via a
`TimestampRegion` — one last-update timestamp per persister (entity class). Any UPDATE to any row of
that class blows away *every* cached list for that class, even unrelated ones; bulk DQL UPDATE/DELETE
bypasses the cache silently unless the caller manually evicts.

## Decision

### Cache shape
Cache the **ordered list of primary keys** matched by a query, not full rows — resolve each id through
the existing per-entity `SecondLevelCache` on hydration. No data duplication between the entity region
and the new query region, same split Doctrine uses conceptually.

### Opt-in API
`QueryBuilder::cacheable(?int $ttl = null): self`. Explicit per Articulate's "explicit over magic"
philosophy — never automatic. Named distinctly from the unrelated `enableResultCache()` (which caches
raw SQL rows by SQL+params text, no entity-identity awareness, generation-counter invalidation). Only
takes effect when `getResult()` will actually hydrate full entities via the default `ObjectHydrator`
(same condition `getResult()` already uses to decide hydration bypass for aggregates/partial selects);
otherwise it's a silent no-op, consistent with every other cache fault in this codebase being fail-open.
Default TTL, when `$ttl` is omitted, reuses `EntityManager`'s `secondLevelCacheTtl` — one TTL concept
for the whole L2 layer rather than a second independent default.

### Invalidation: reverse-index + per-table generation counter

Two different change shapes get two different mechanisms, not one blunt "evict everything for this
table" hammer:

**(a) An already-cached row changed or was deleted — exact invalidation.** When a query's id-list is
cached, also write reverse-index entries keyed `tableName:normalizedId -> [cacheKey, ...]` — **table-
scoped, not globally keyed by raw id alone.** `EntityCacheCoordinator` already walks every
changed/deleted/soft-deleted entity in `invalidateSecondLevelCache($aggregatedChanges)` — extend that
loop to resolve the entity's table (same `getTableName()` call `evictEntityFromSecondLevelCache()`
already makes) and its normalized id (reusing `SecondLevelCache`'s type-tagged normalization, to avoid
int/string id collisions), look up the reverse index for that `table:id` pair, and evict exactly the
query-cache entries it names.

The table-scoping is deliberate and required, not incidental: context-bounded sibling classes sharing
a table/PK must resolve to the *same* reverse-index entry (a write through `AdminUser` must invalidate
a cached query result reached through sibling `LoginUser`, since both map the same row) — but two
*unrelated* tables that happen to share a coincidental raw PK value (e.g. `users.id=2` and
`orders.id=2`) must never collide into the same entry, or a write to one table would wrongly evict a
cached query against a completely different table, breaking the unrelated-table-isolation guarantee
(see "Filter-state and `chunk()` interaction" / testing requirements below). Keying by table scopes the
index exactly to "same table, same id" — which is exactly Articulate's definition of "same underlying
row" for context-bounded entities — and no narrower or wider.

**(b) A new row inserted that should now match a previously-cached query — can't be detected exactly**
(nobody, not even Doctrine, re-evaluates a cached query's WHERE clause against a new row in memory).
Fold a **per-table insert-generation counter** into every query-cache key (`tableName:generation`,
same mechanism `EntityCacheCoordinator::incrementQueryCacheGeneration()` already uses, just keyed per
table instead of one global counter). An INSERT recorded in `$aggregatedChanges` for a table bumps that
table's counter; old keys stop matching and age out via TTL, no explicit sweep needed.

**Reverse-index TTL** is set to the query-cache entry's TTL **plus a fixed grace buffer** (e.g. +300s),
so the index always outlives the entry it points at — a stale/orphaned reverse-index entry is harmless
(it just points at an already-expired key, a clean miss).

**Why this beats Doctrine's `TimestampRegion`:** per-id precision instead of per-class — an unrelated
UPDATE to a different row of the same class never evicts a cached list that didn't contain it. No
silent gap for bulk operations, because everything in Articulate routes through `$aggregatedChanges`
at flush time — there is no separate "bulk DQL" code path that bypasses the hook the way Doctrine's does.

**Known cost tradeoff (accepted, not optimized away):** up to N reverse-index writes per cached query
(one per cached id) and one reverse-index lookup per changed/deleted entity at flush time. Only paid by
queries that opted into `cacheable()`. A hot id referenced by many distinct cached queries accumulates
an unbounded (until TTL) reverse-index entry list — no cap/dedup is applied; this is a deliberate
"don't optimize before profiling" call, documented here rather than solved speculatively.

**PSR-6 eviction opacity — closed via read-time cross-validation.** TTL + grace buffer protects against
the reverse-index *expiring* before the entry it guards, but a PSR-6 pool under memory pressure (e.g.
Redis `allkeys-lru`) can evict a key early regardless of TTL. At flush time alone this is unrecoverable:
an empty reverse-index entry for a changed/deleted id is indistinguishable from "never referenced" —
there is no signal to tell the two apart from that side.

At **read time**, the ambiguity resolves: by the write-time invariant (a query-cache entry and the
reverse-index entries for every id it contains are always written together), a reverse-index entry's
absence for an id that a *currently cached list* claims to contain can only mean it existed and was
evicted since — never "it never existed", because the list's own existence is proof the index was
written at the same moment. This lets the read path detect eviction-induced staleness that flush-time
invalidation alone cannot.

**Protocol — three outcomes on a `cacheable()` query resolution:**
1. **List-cache hit, reverse-index intact for every id in it** (checked via a single batched
   `CacheItemPoolInterface::getItems()` call, not N round-trips) — trust the cached list, serve normally.
2. **List-cache hit, reverse-index missing for one or more of its ids** — treated as a detected
   inconsistency, not silently served. The *entire* cached list entry is discarded (not partially
   repaired — whole-list discard chosen over per-id patching for simplicity: no partially-healed
   intermediate state to reason about) and the query runs fresh; writing the new list + reverse-index
   naturally repairs the link as a side effect of the normal write path.
3. **List-cache miss entirely** (no list, no index) — ordinary first-query path; hydration still
   prefers resolving each row through the existing per-entity `SecondLevelCache`/managed-entity-store
   path rather than assuming nothing is cached (already-existing `QueryResultExecutor` behavior, not
   new).

This fully closes the previously-unfixable update-case gap: if a flush-time invalidation silently
missed evicting a query-cache entry because its reverse-index was already gone, that same absence is
observed on the very next read and triggers outcome 2 — stale data is never served past that point. The
accepted cost is explicit: every cache hit now pays one additional batched reverse-index existence
check alongside the id resolution it already performs. A false positive (reverse-index evicted for an
unrelated, harmless reason after a correct flush already ran) costs one extra requery, never incorrect
data — consistent with this codebase's existing bias toward failing back to a fresh query rather than
risking stale output.

**Serve-time ghost filtering (still kept, independent of the above):** resolving a cached id into an
entity that turns out to have been deleted (null resolution) drops that id from the served result
without discarding the whole list — a narrower, cheaper complement to outcome 2 for the pure-deletion
case, costing nothing beyond the hydration lookup already being performed.

### Module placement

New `src/Modules/Cache/` module holds only the new code: `CollectionCacheInterface` +
`CollectionCache` (PSR-6-backed storage for id-lists and reverse-index entries, key prefixes `l2q:`/
`l2qidx:`, distinct from `SecondLevelCache::generateKey()`'s plain per-entity hash) and
`NullCollectionCache` (no-op Null Object, always miss). The existing `SecondLevelCache`/
`EntityCacheCoordinator` in `EntityManager` are untouched — zero breaking change to that public
namespace. `EntityCacheCoordinator` gains a dependency on the new `Cache` module to drive reverse-index
eviction and generation bumps at flush time.

This is a narrower module than folding the existing per-entity L2 cache into the same place would be;
a full unification (moving `SecondLevelCache`/`EntityCacheCoordinator` into `Cache` too) is deliberately
left for a future ADR, since it would be a breaking namespace change to existing public classes —
out of scope for this feature.

`deptrac.yaml` gains a `Cache` layer (`src/Modules/Cache/`), depending only on `Exceptions`/`Utils`.
Both `QueryBuilder` and `EntityManager` are allowed to depend on it. This is required, not optional:
`QueryBuilder` cannot depend on `EntityManager` under the existing ruleset (only the reverse direction
is allowed), so the collection-cache storage cannot live inside `EntityManager` as originally sketched
in the implementation handoff — it must be an independent lower layer both sides can reach.

`QueryBuilder`'s own collaborator (`QueryResultSecondLevelCache`, builds cache keys, decides hit/miss,
resolves ids through the existing `managedEntityStore`/`SecondLevelCache` hydration path) takes a
non-nullable `CollectionCacheInterface $collectionCache = new NullCollectionCache()` constructor
default — a Null Object, not a nullable parameter — so no `?->` branching is needed in the new
collaborator. `EntityReadService::createQueryBuilder()` passes the real `CollectionCache` only when a
second-level cache pool is configured on the owning `EntityManager`; otherwise the null object flows
through silently, same end behavior as today's "no L2 cache configured" case.

### Filter-state and `chunk()` interaction

No special-cased key component is needed for filter state (e.g. `SoftDeleteFilter`): disabled filters
already change the compiled SQL via `QueryBuilder::getFilteredWhere()`, and the cache key is derived
from that compiled SQL — different filter states already produce different keys with zero extra work.

`chunk()` requires no separate cache-awareness. Each `getResult()` call inside its loop has its own
`limit`/`offset`, both already part of key generation, so every page is cached as an independent
id-list under its own key. The per-table generation counter (see (b) above) already prevents a stale
page from being served across a chunked iteration if a row was inserted mid-iteration — the next page's
key simply changes and misses.

## Considered options

- **Cache full raw rows per query** (not just id-lists) — rejected: duplicates data already held by the
  per-entity cache and significantly complicates invalidation (two copies of the same row data to keep
  in sync).
- **Doctrine-style `TimestampRegion`** (per-class last-write timestamp, evict whole region) — rejected,
  see "why this beats Doctrine's approach" above: imprecise (per-class, not per-id) and has a silent
  gap for bulk operations in a Doctrine-equivalent sense that Articulate's flush-time
  `$aggregatedChanges` hook doesn't have to live with.
- **Fold the new `Cache` module together with the existing `SecondLevelCache`/`EntityCacheCoordinator`**
  — rejected for this feature: breaking change to an existing public namespace, out of scope; left for
  a possible future ADR.
- **Nullable `?CollectionCacheInterface` constructor parameter** — rejected in favor of a Null Object
  default: removes conditional-null branching from the new collaborator entirely, and the project
  already favors depending on abstractions over concrete nullable collaborators.

## Consequences

- A future reader sees `QueryBuilder` and `EntityManager` both depending on a `Cache` module that holds
  less than "all caching" — it only holds the new collection/query-region code. That split is
  deliberate, not an oversight; the per-entity `SecondLevelCache` stays where it is.
- Hot entity ids referenced by many cached queries carry unbounded-until-TTL reverse-index entry lists;
  a documented, accepted cost, not a defect.
- `cacheable()` queries pay reverse-index write/lookup cost; queries that never opt in pay nothing,
  consistent with "explicit over magic".

## See also

[Known limitations guide](/guides/known-limitations/) — update once this ships, rewriting the existing
"Second-level cache serves `find()` by class and primary key..." entry rather than appending a
contradicting paragraph.
