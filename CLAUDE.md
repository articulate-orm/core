# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

All commands run inside the PHP container (`docker compose exec php bash`):

```bash
# Setup
docker compose up -d
docker compose exec php bash
composer install

# Testing
composer test                                          # All tests
composer test:coverage                                 # HTML coverage → build/coverage/
composer test:mutation                                 # Infection mutation testing
composer test -- --filter=TestClassName                # Single test class
composer test -- --filter=TestClassName::testMethod    # Single test method
composer test -- --testsuite="Database Tests"          # Multi-DB tests only
composer test -- --group=mysql                         # MySQL tests only
composer test -- --group=pgsql                         # PostgreSQL tests only

# Code quality
composer cs:check          # Dry-run php-cs-fixer
composer cs:fix            # Apply CS fixes
composer static:check      # PHPStan level 6
composer architecture:check  # Deptrac layer validation
composer complexity:check   # PHPMD cyclomatic/NPath analysis

# Full QA pipeline
composer qa                # CS → architecture → tests → mutation tests
```

## Project Overview

Articulate is an ORM for PHP domain-driven applications where multiple entity classes map to the same database table (context-bounded entities). Primary users are backend developers building bounded-context architectures. The core optimization is keeping entity state tracking per-context rather than process-wide — each `EntityManager` instance owns its own `IdentityMap`. When in doubt: prefer explicitness over magic, bounded scope over global state.

## Tech Stack

- **Language**: PHP 8.4+ (use readonly properties, enums, fibers where appropriate)
- **Databases**: MySQL 8.0, PostgreSQL 15 — both must work
- **Mapping**: PHP attributes only — no XML, no YAML, no annotations
- **Testing**: PHPUnit with real DB connections — no mocks
- **QA**: php-cs-fixer, PHPStan level 6, Deptrac, Infection mutation testing
- **Do NOT use**: Doctrine annotations, global singletons, static state, process-wide registries

## Architecture

Articulate is a context-bounded ORM for domain-driven PHP applications. PHP 8.4+, attribute-based configuration, MySQL 8.0 + PostgreSQL 15.

### Core Concepts

**Context-bounded entities**: Multiple entity classes can map to the same database table (e.g., `LoginUser` and `ProfileUser` both map to `users`). This is the primary differentiator from standard ORMs.

**Unit of Work**: `EntityManager` tracks entity state (NEW → MANAGED → REMOVED) via `UnitOfWork`. Changes collected by `ChangeAggregator`, flushed as a batch. Each context (EntityManager instance) has its own `IdentityMap` — no process-wide singletons.

**Cross-entity remove propagation**: When `remove()` is called on an entity, `UnitOfWork` automatically marks all other MANAGED entities in the same `IdentityMap` that share the same table and primary key as REMOVED. Only one `DELETE` is issued — sibling entities are just removed from tracking to prevent ghost `IdentityMap` reads and phantom UPDATE calls on the deleted row. Lifecycle callbacks (`preRemove`/`postRemove`) are only invoked on the explicitly removed entity, not on its siblings.

**Attribute-driven metadata**: No XML/YAML. All mapping via PHP attributes: `#[Entity]`, `#[Property]`, `#[PrimaryKey]`, `#[Index]`, `#[OneToMany]`, `#[ManyToMany]`, etc.

### Module Map

| Module | Path | Purpose |
|--------|------|---------|
| EntityManager | `src/Modules/EntityManager/` | UnitOfWork, IdentityMap, hydrators, lifecycle callbacks, lazy-loading proxies |
| QueryBuilder | `src/Modules/QueryBuilder/` | Fluent SQL builder, WHERE clauses, keyset pagination, soft-delete filter |
| Database | `src/Modules/Database/` | Schema reader, type mappers per DB, schema comparator (diff engine) |
| Repository | `src/Modules/Repository/` | AbstractRepository, EntityRepository, criteria pattern |
| Migrations | `src/Modules/Migrations/` | Schema diff → migration SQL (MySQL & PostgreSQL generators) |
| Cache | `src/Modules/Cache/` | Collection (query-region) cache storage: id-lists and table generations |
| Generators | `src/Modules/Generators/` | ID strategies: UUID v4/v7, ULID, AutoIncrement, Serial, Prefixed |
| Attributes | `src/Attributes/` | All PHP attributes + reflection wrappers (ReflectionEntity, ReflectionProperty, ReflectionRelation) |
| Schema | `src/Schema/` | EntityMetadata, EntityMetadataRegistry, naming conventions |
| Utils | `src/Utils/` | TypeRegistry, type converters (bool↔TINYINT, DateTime↔DATETIME, etc.) |
| Commands | `src/Commands/` | Symfony Console: DiffCommand, InitCommand, MigrateCommand, ValidateCommand, WarmMetadataCacheCommand |

### Architectural Boundaries

Deptrac enforces 13 layers. The dependency direction is:
`Commands → Migrations → Database → QueryBuilder → Repository → EntityManager → Schema → Attributes → Cache → Utils → Collection → Generators → Exceptions`

Run `composer architecture:check` after adding cross-module dependencies.

### Where New Things Go

| Adding... | Goes in... |
|-----------|-----------|
| New entity mapping attribute | `src/Attributes/` |
| New DB type converter | `src/Utils/` |
| New hydration strategy | `src/Modules/EntityManager/Hydrators/` |
| New query feature | `src/Modules/QueryBuilder/` |
| New schema diff concern | `src/Modules/Database/SchemaComparator/` |
| New ID generation strategy | `src/Modules/Generators/` |
| New CLI command | `src/Commands/` |
| New test for EntityManager | `tests/Modules/EntityManager/` |

Always run `composer architecture:check` after adding cross-module dependencies.

### Schema Comparator (Diff Engine)

`DatabaseSchemaComparator` in `src/Modules/Database/SchemaComparator/` compares live DB schema against entity attributes. Uses per-concern comparators:
- `ColumnComparator` — column type/nullability/default diffs
- `EntityTableComparator` — table-level diffs
- `ForeignKeyComparator`, `IndexComparator`, `MappingTableComparator`

Output feeds `MigrateCommand` and `DiffCommand`.

### Hydration

Four hydrators in `src/Modules/EntityManager/Hydrators/`:
- `ObjectHydrator` — full entity objects (default)
- `ArrayHydrator` — raw arrays
- `ScalarHydrator` — single scalar value
- `PartialHydrator` — subset of properties
- `LazyLoadingHydrator` — wraps ObjectHydrator with proxy injection for deferred relation loading

### Proxy System

`ProxyGenerator` generates PHP proxy classes at runtime for lazy loading. Proxies intercept property access and trigger `LazyLoadingHydrator`. Generated proxies cached in configured proxy directory.

## Coding Conventions

- **Naming**: Classes = PascalCase, methods/vars = camelCase, DB columns = snake_case via naming convention resolver
- **Types**: Full type hints everywhere — no `mixed` unless unavoidable, no `@param` when signature suffices
- **No comments** unless the WHY is non-obvious (hidden constraint, workaround, subtle invariant)
- **Interfaces before implementations**: depend on abstractions, not concrete classes
- **Exceptions**: use types from `src/Exceptions/` — never throw `\Exception` directly
- **File size**: keep classes focused; if a class exceeds ~300 lines, consider splitting by concern
- **No static state**: zero static properties/methods that accumulate state across requests
- **Dual-DB**: every SQL-touching feature must work on both MySQL and PostgreSQL — use `match($databaseName)` in tests

## Patterns

### Read Replicas

No built-in read/write routing — intentional. Let infrastructure handle it (PgBouncer, ProxySQL, RDS Proxy) or use the per-context design:

```php
// Write context → primary
$primary = new EntityManager($primaryConnection, ...);
$primary->persist($entity);
$primary->flush();

// Read context → replica
$replica = new EntityManager($replicaConnection, ...);
$users = $replica->findAll(User::class);
```

Each `EntityManager` owns its `IdentityMap` and `UnitOfWork` — calling `flush()` on a replica-backed instance is a caller error, not something the ORM prevents. Document this contract in consuming code.

### Second-Level Cache

`SecondLevelCache` (`src/Modules/EntityManager/`) caches raw entity rows keyed by `class + id` behind a PSR-6 pool. Pass `secondLevelCache:` (and optional `secondLevelCacheTtl:`) to `EntityManager` — it is the only switch; `resultCache:` alone never enables it. `find()` reads through it; `flush()` evicts changed/deleted IDs. Cache faults never break query execution — every operation is wrapped and fails open.

Contract — read before relying on it:
- **Cross-context staleness is by design.** Eviction only happens in the `EntityManager` that performed the write. A write in context A leaves a stale row readable in context B until its TTL expires. Keep `secondLevelCacheTtl` short for entities shared across contexts, or evict explicitly. Same footgun class as the replica `flush()` rule above.
- **Sibling-class eviction is automatic within one `EntityManager`.** When `flush()` writes or deletes a row, the cache is evicted for every entity class mapped to the same table that the registry has seen in this session (via `EntityMetadataRegistry::getClassesByTable`). Example: deleting `Customer(id=1)` also evicts the `CustomerSummary(id=1)` entry. Classes never loaded in the session are not evicted — cross-`EntityManager` staleness still applies.
- **Caches the root row only, not relations.** A cache hit returns a shallow hydrate; relations still lazy-load on access (consistent with a cold `find()`).
- **IDs must be scalar, `Stringable`, or a composite array.** `EntityCacheCoordinator` first canonicalizes every id to the primary-key property's PHP type (same converter/cast as hydration), so `find(User::class, '1')`, a raw DB row value and the typed property used for flush eviction all hit one entry. Keys are then type-tagged, so ids of different classes/types never collide; non-`Stringable` objects throw (caught upstream → caching silently disabled for that ID).

### Collection Cache

`QueryBuilder::cacheable(?int $ttl)` caches the ordered **id-list** a query matched (never row data) in the second-level cache pool via `CollectionCache` (`src/Modules/Cache/`); a hit resolves entities through the identity map / `SecondLevelCache`. Only applies to default object hydration of an entity with a single-column primary key. Invalidation is by **table generation** (terms in `CONTEXT.md`).

Contract — read before relying on it:
- **Any write to the root table invalidates.** Each key folds in the root table's generation. A flush bumps it once per written table, for inserts, entity updates, merged updates, hard deletes and soft deletes — however many rows, through whichever sibling slice. Old lists stop matching and age out via TTL. This covers an UPDATE moving a row into or out of a cached predicate.
- **Coarse by design.** Any write to a table evicts every list cached against it. Correctness over hit rate; choose which queries are worth caching.
- **Joined tables are dependency tables.** Every table reached through `join`/`leftJoin`/`rightJoin`/`crossJoin` adds its generation to the key (alias stripped, self-join counted once), so a write to it invalidates the list too. Trade-off: the list now misses on writes to any of those tables, so a join against a busy table lowers the hit rate. Collection lives in `DependencyTables` (`src/Modules/QueryBuilder/`). Subquery tables are not covered yet.
- **Joins qualify the default select.** With any join, an entity query's default column list is prefixed with the FROM table/alias, so shared column names (and self-joins) are not ambiguous.
- **Unit-of-work writes only.** `QueryBuilder` DML and raw SQL do not bump generations.
- **Shared across contexts.** Generations live in the shared pool, so a write in one `EntityManager` invalidates lists every context reads (unlike per-entity eviction).
- **Cost:** one batched `getItems()` per `cacheable()` query for all generations (root plus dependency tables).
- **Fail-open, with caveats.** A backend fault never breaks a query: an unreadable generation skips the cache for that call. But a failed bump during an outage leaves pre-change lists matchable after recovery until TTL, and the bump runs before COMMIT, so a concurrent reader can re-cache pre-commit state.

### Metadata Cache

`EntityMetadataRegistry` builds `EntityMetadata` (property/relation/attribute reflection) once per class and keeps it in memory — but that in-memory cache dies with the process. Pass `metadataCache:` (a PSR-6 `CacheItemPoolInterface`) via `EntityManagerOptions`/`EntityManagerFactory::create()` to persist it (Redis, APCu, a filesystem adapter, whatever pool you bring) so a fresh process reads pre-computed metadata instead of re-walking reflection and attributes.

Contract — read before relying on it:
- **No TTL, no auto-invalidation.** Metadata doesn't change at runtime, so entries never expire on their own. Editing entity attributes requires an explicit `EntityMetadataRegistry::clearMetadata()`/`clearAll()` call (which evicts from the pool too) — typically as a deploy step. Forgetting this serves stale mapping data, same footgun class as second-level cache staleness above.
- **Cache faults never break resolution.** A pool read/write failure falls back to computing metadata directly — consistent with every other cache in this codebase.
- **`ReflectionProperty`/`ReflectionRelation`/`ReflectionManyToMany`/`ReflectionMorphToMany`/`ReflectionMorphedByMany`/`EntityMetadata` implement `__serialize`/`__unserialize`.** They hold native, non-serializable `\ReflectionClass`/`\ReflectionProperty` objects; serialization stores the derived plain data instead and rebuilds the native reflection handle cheaply via `ReflectionCache` on wakeup — no re-parsing of attributes on a cache hit.
- **`articulate:warm-metadata-cache` command** (`src/Commands/WarmMetadataCacheCommand.php`) scans one or more entities directories via `EntityClassDiscovery` (shared with `articulate:validate` and `articulate:diff` — `$entitiesPath` takes an `array<string>` of directories, or `null` to fall back to `src/Entities`/`src/Entity`) and populates the pool ahead of time, e.g. as part of a deploy.

### Optimistic Locking

Bump/check is fully explicit, per-class, from that class's own attributes only — no cross-sibling discovery at runtime, no table-wide lookup on every flush. `#[Version]` (property-level) is a class's canonical version column: hydrated as a normal `int` property, bumped (`version = version + 1`) **and** checked (`WHERE version = ?`, against the tracked value) on every `UPDATE` through that class. It implies `#[Property]` — a bare `#[Version]` property persists on its own — and takes an optional explicit column name, `#[Version(name: 'lock_version')]`, with the same semantics as `#[Property(name:)]`. `#[VersionAware(['column', ...])]` (class-level) is an inert acknowledgement marker: it emits no SET or WHERE contribution and triggers no bump. It only names the version columns a slice acknowledges writing, so `articulate:validate` can confirm a sibling that legitimately writes through a versioned table has consciously opted out of lost-update detection it can't reason about.

Contract — read before relying on it:
- **No attribute at all on a class mapping a versioned table is a real gap, not silently tolerated.** That class's writes are invisible to a checking sibling's lost-update detection. `articulate:validate` errors on it.
- **`#[VersionAware]` is an inert acknowledgement marker.** It emits no SET or WHERE contribution and triggers no bump — it only names the version columns a slice acknowledges, for `articulate:validate` to consume via `EntityMetadata::getAcknowledgedVersionColumns()`. A column appearing in both a class's own `#[Version]` property and its own `#[VersionAware]` list is merely redundant, not an error.
- **`#[Version]` properties must be typed `int`**, enforced at metadata-build time. A migration-generated column for one gets `DEFAULT 0` automatically, matching the property's own zero-value default. `#[Version]` together with `#[Property]` on the same property stays legal and simply redundant.
- **Runtime is table-lookup-free.** `VersionedRowWrite` resolves bump and check entirely from the acting entity's own `EntityMetadata::getVersionColumns()` (a single accessor now that bump and check lists are identical — zero or one column, the slice's own `#[Version]` column) — no `EntityMetadataRegistry` dependency at flush time.
- **`OptimisticLockException` doesn't distinguish a stale version from a deleted row** — both surface as "zero rows matched", same conflation as Doctrine's equivalent.
- **The in-memory `#[Version]` property bump is commit-then-abandon around the flush, not eager.** `VersionedRowWrite` (`src/Modules/EntityManager/`) owns the whole versioned row write for entity updates (UPDATE and soft-delete): the `SET v = v + 1` bump, the `WHERE v = ?` check, zero-matched-rows becoming `OptimisticLockException`, and the in-memory property change. `QueryExecutor::executeUpdate()`/`executeSoftDelete()` delegate to it and return a `VersionedRowWriteHandle`; `ChangeSetExecutor::execute()` collects them and `EntityManager::flush()` calls `commit()` on all of them *after* `execute()` fully succeeds but *before* post-update callbacks, then `clearChanges()` snapshots the bumped value on the commit path, or the `catch` block calls `abandon()` on every handle before rethrowing. `abandon()` writes the captured original, so it is a safe no-op when `commit()` never ran (e.g. `execute()` threw). Net effect: a committed flush leaves the property at `N+1`; a thrown/rolled-back flush leaves it at `N`; a mid-flush conflict never strands a successfully-updated sibling, because `commit()` runs only once every UPDATE in the batch has passed its check. Consequences: (a) the DB-side `SET version = version + 1` still runs inline, so writing one row through two `#[Version]`-checking classes in a single flush still self-conflicts on the second UPDATE; (b) `#[PostUpdate]`/post-remove callbacks observe the bumped `N+1` value, matching the row; (c) when `flush()` runs inside a caller-owned transaction it does not commit or roll back — `commit()` still runs at flush end, and a *later* caller rollback (after `flush()` returned) strands the bump. `flush()`'s own transaction is the only fully rollback-safe form; wrapping `flush()` in a caller transaction (directly or via `EntityManager::transactional()`) only narrows the window, it does not close it.
- **A `#[Version]`-checking entity's update is never combined by `MergeUpdateConflictResolutionStrategy`** — it always goes through the entity-bound path where the check is honored. Entities carrying only `#[VersionAware]` remain combinable, and contribute no version SQL to the merged UPDATE (the marker is inert).
- **The version-guard rules live in `SliceVersionGuardCheck`** (`src/Schema/VersionGuard/`), not in the command. `check(EntityMetadata ...$slices)` groups slices by table itself and returns typed `VersionGuardFinding`s (kind, table, slice class, version column(s), affected columns, `message()`). `VersionGuardViolation::downgradable()` owns the "never downgraded" policy; `--lenient` is a CLI-only concern — `ValidateCommand` just maps each finding to error/warning, counts, and sets the exit code. Add new version-guard rules there and test them against typed findings (`tests/Schema/VersionGuard/`), not console text. Terms (slice, sibling, guard set, acknowledgement, rival counters) are defined in `CONTEXT.md`.
- **`articulate:validate`** groups entities by table and flags, per-slice: two distinct `#[Version]` columns with overlapping guard sets ("rival counters", error, never downgraded), a slice persisting a column inside another slice's guard set without its own `#[Version]` or a `#[VersionAware]` acknowledgement (error, downgraded to a warning under `--lenient`), and a slice mapping another slice's `#[Version]` column as a plain `#[Property]` (error, never downgraded, `#[VersionAware]` doesn't clear it — runtime `ManagedVersionColumnException` only guards the owning class, so this is validate-only).

### Enum Properties

Backed enums persist their backing value, pure enums their case name. `TypeRegistry` maps int-backed enums → `INT`, everything else → `VARCHAR(255)`, and lazily builds an `EnumTypeConverter` per enum class (nullable `?Enum` handled too). No registration needed — just type the property with the enum.

### Transaction Helpers

`Connection::transactional(callable, maxRetries, baseDelayMs)` runs work in a transaction and retries on deadlock / serialization failure (SQLSTATE 40001/40P01, MySQL 1213/1205) with exponential backoff; nested calls run in the caller's transaction without committing. Savepoints: `createSavepoint`/`releaseSavepoint`/`rollbackToSavepoint`. `flush()` does **not** auto-retry — wire `transactional()` at the call site if you need it.

## Safe-Change Rules

CLAUDE.md and README.md should be kept up-to-date with the codebase.

Do NOT casually modify:
- `UnitOfWork` state machine transitions (NEW → MANAGED → REMOVED) — breaks flush correctness
- `IdentityMap` keying logic — breaks entity identity guarantees
- Public API of `EntityManager` (method signatures, return types) — downstream breaking change
- Deptrac layer config (`deptrac.yaml`) — only change with architectural intent
- Attribute class names/constructors — breaks all existing user code using those attributes
- Schema comparator output format — feeds migration SQL generators

Flag these to the user before implementing.

## Testing

Tests use **real database connections, no mocks**. Each test runs in a transaction that auto-rolls back.

### Multi-database tests

Extend `DatabaseTestCase`, use `@dataProvider databaseProvider` for tests that run on both MySQL and PostgreSQL:

```php
/** @dataProvider databaseProvider */
public function testFeature(Connection $connection, string $databaseName): void
{
    $this->setCurrentDatabase($connection, $databaseName);
    $tableName = $this->getTableName('my_table', $databaseName);
    // database-specific SQL via match($databaseName) { 'mysql' => ..., 'pgsql' => ... }
}
```

### Database environment variables

```env
DATABASE_HOST=127.0.0.1        # or "mysql" inside Docker
DATABASE_HOST_PGSQL=127.0.0.1  # or "pgsql" inside Docker
DATABASE_USER=root
DATABASE_PASSWORD=rootpassword
DATABASE_NAME=articulate_test
```

See `tests/ExampleMultiDatabaseTest.php` for reference patterns.
