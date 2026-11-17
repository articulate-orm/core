# Changelog

All notable changes to `articulate-orm/core` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- New `QueryBuilder::cacheable(?int $ttl = null)`: opt a query into the collection (query-region)
  second-level cache. The matched id-list is cached; entities resolve from the identity map or the
  per-entity second-level cache, and any unresolvable id discards the whole cached list and re-queries.
  Silent no-op without a configured `secondLevelCache` pool, for `lock()` queries, aggregates,
  custom hydrators and composite primary keys.
- Second-level cache keys (collection and per-entity) are now namespaced per connection
  (`Connection::getCacheNamespace()`), so connections to different databases can share one pool
  safely. Existing per-entity entries are not reused after upgrading.
- Table-generation invalidation: every table has one generation counter (`l2qgen_` + sha256 of
  connection namespace and table, randomly seeded). A flush bumps it once per written table, for
  inserts, entity updates, merged updates, hard deletes and soft deletes. A cached id-list is keyed
  by its root table's generation, so any such write makes it stop matching, including an update that
  moves a row into or out of the cached predicate and a merged update across sibling slices.
  `QueryBuilder` DML and raw SQL do not invalidate yet.
- Joined tables are dependency tables of a `cacheable()` query: the key folds in the generation of
  every table reached via `join`/`leftJoin`/`rightJoin`/`crossJoin` (alias stripped, self-join counted
  once), read in one batched pool call, so a write to a joined table invalidates the list. Trade-off:
  more writes now evict a joined list, lowering its hit rate.
- Subquery tables are dependency tables of a `cacheable()` query: a `QueryBuilder` subquery passed to
  `whereExists`/`orWhereExists`/`whereIn`/`whereNotIn` contributes its own table, its joins and its
  nested subqueries (recursively), so a write to any of them invalidates the list. Raw-SQL subqueries
  are not detected.
- `cacheable(?int $ttl = null, array $dependsOn = [])`: declare extra dependency tables by name for
  reads the builder can't detect (`whereRaw`, raw join conditions). Merged and deduplicated with
  detected tables (declaring one already detected keeps the same key) and kept across `resetJoins()`.
  Undeclared raw-SQL dependencies are not detected.
- Mapping (pivot) tables written by the unit of work bump their table generation once per flush:
  pivot rows deleted along with a removed entity and many-to-many / morph-to-many collection sync
  (inserts, pivot-data updates, deletes). A cached list joining a mapping table is invalidated.
- **Fixed:** an entity query with joins now qualifies its default column list with the FROM table or
  alias, so columns shared with a joined table (or a self-join) are no longer ambiguous.
- All collection-cache keys (`l2q_`, `l2qgen_`) contain only PSR-6-safe characters (no `:`), so
  strict pools such as Symfony Cache accept them.
- `symfony/cache` added as a dev dependency (PSR-6 key validation in tests).
- **Changed:** the per-entity second-level cache is enabled only by `secondLevelCache:`. It no longer
  falls back to the `resultCache:` pool, so configuring `resultCache:` alone leaves `find()` uncached.
  Pass the same pool as `secondLevelCache:` to keep the previous behaviour.
- **Fixed:** `find()` with an id whose PHP type differs from the primary-key property (e.g. a string
  route parameter for an `int` id) cached the row under a key `flush()` never evicted, serving stale
  data until TTL. Ids are now canonicalized to the primary-key property's type before keying.
- `SecondLevelCache::setMany()` fetches all items with one `getItems()` call instead of one
  `getItem()` per row, cutting a `cacheable()` miss from N+1 to a constant number of round trips.

## [2.0.0]

- Optimistic locking moves from a **row-wide** version model to a **per-slice** one (BREAKING). See `docs/adr/0001-per-slice-version-guards.md`.
- `#[Version]` now guards only its **guard set** — the `#[Property]` columns declared
  on the slice that owns it, minus the primary key and the version column itself. A
  slice touching none of a guard set's columns emits no version SQL and cannot conflict
  with it. A cosmetic write no longer raises spurious `OptimisticLockException`s in the
  slice that actually cares about lost updates. A table may carry several independent
  `#[Version]` columns, one per concern.
- `#[Version]` now implies `#[Property]` and accepts an optional column name,
  `#[Version(name: 'lock_version')]`, with the same semantics as `#[Property(name:)]`.
  A bare `#[Version]` property persists on its own.
- `#[VersionAware(['column', ...])]` is now an **inert acknowledgement marker**: no SET,
  no WHERE, no bump at runtime. It only names the version columns a slice knowingly writes
  through without taking on their lost-update detection, so `articulate:validate` treats
  the crossing as consciously declared rather than a gap.
- `articulate:validate` version checks rewritten to the per-slice model: it errors on
  **rival counters** (two distinct `#[Version]` columns with overlapping guard sets, never
  downgraded) and on a slice persisting a column inside another slice's guard set without
  its own `#[Version]` or a `#[VersionAware]` acknowledgement. A new `--lenient` flag
  downgrades the missing-acknowledgement error to a warning.
- `articulate:validate` errors on a slice mapping another slice's `#[Version]` column as
  a plain `#[Property]`, which could otherwise overwrite the counter unchecked. Never
  downgraded; `#[VersionAware]` does not clear it.

## [1.2.1]

- Patch release on the 1.2.x line.
- Package moved to the `articulate-orm` org and renamed `denisyu-1/articulate` → `articulate-orm/core`.

## [1.2.0]

- Optimistic locking (row-wide `#[Version]` / `#[VersionAware]` model, superseded by the
  per-slice rework above).
