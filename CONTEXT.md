# Context — Articulate Core

Glossary of domain terms used across this repo. Devoid of implementation detail by design —
see ADRs under `docs-site/src/content/docs/reference/` for the "why" behind specific decisions.

## Caching

- **Second-level cache (L2 cache, entity cache)**: Per-`(class, primaryKey)` cache of a single
  entity's raw row data, behind a PSR-6 pool. Owned by `SecondLevelCache` +
  `EntityCacheCoordinator` (`src/Modules/EntityManager/`). Read/written only by
  `EntityManager::find()`.
- **Collection cache (query-region cache)**: Cache of the ordered list of primary keys matched by
  a `QueryBuilder` query — not the row data itself. Opt-in via `QueryBuilder::cacheable()`. On a
  hit, each cached id is resolved through the existing second-level cache / managed-entity-store
  hydration path, never duplicating row data between the two caches. Owned by the new
  `src/Modules/Cache/` module (`CollectionCache`/`CollectionCacheInterface`/
  `NullCollectionCache`), consulted from `QueryBuilder` via a `QueryResultSecondLevelCache`
  collaborator.
- **Result cache**: The pre-existing, unrelated `QueryResultCache` (`enableResultCache()`).
  Caches raw SQL result rows keyed by SQL text + bound params, with no entity-identity awareness.
  Invalidated by a single global generation counter bumped on every flush — distinct mechanism
  from both caches above, not touched by the collection-cache feature.
- **Reverse index**: `entityId -> [cacheKey, ...]` mapping maintained by the collection cache so
  that, when an entity changes or is deleted, every cached query result that contained it can be
  evicted exactly — without re-evaluating any query's WHERE clause.
- **Per-table generation counter**: A counter, one per database table, folded into every
  collection-cache key as `tableName:generation`. Bumped whenever a row is inserted into that
  table. Exists because an *insert* that should now match a previously-cached query can't be
  detected by exact invalidation (no row identity to look up in the reverse index yet) — bumping
  the counter makes the old cache key stop matching, so the next read is a natural cache miss
  rather than a stale hit.
- **Context-bounded entity (slice)**: Multiple entity classes mapping to the same physical table
  (e.g. `LoginUser`/`AdminUser` both on `users`). The project's primary differentiator from
  standard ORMs. Sibling slices sharing a table/PK resolve to the same cache identity in both the
  entity cache and the collection cache's reverse index — no separate handling needed for this
  case in either cache.
