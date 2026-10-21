# Spec: type the flush update list; delete dead merge version code

Status: done
Origin: architecture review 2026-10-21, candidate 2
Terms: **entity update**, **merged update** — see `CONTEXT.md` § Flush

## Problem

The update list passed from `UpdateConflictResolutionStrategy::resolve()` to `ChangeSetExecutor`
and `EntityCacheCoordinator` is an untyped array union — `{entity, changes}` or
`{table, entity, set, where, whereValues, versionBumpColumns}` — and every consumer branches on
`isset($update['table'])`.

`MergeUpdateConflictResolutionStrategy` also carries unreachable version code:
`canCombineUpdate()` (`:24`) rejects every slice with a `#[Version]` column before `:39` runs, so
`$versionBumpColumns` is always `[]`, the dedupe at `:66-72` never fires, and its comment describes
a design ADR-0001 removed. The strategy's input-side `isset($update['table'])` (`:15`) is dead too:
`ChangeAggregator::aggregateChanges()` only ever passes entity updates.

## Constraints

- `UpdateConflictResolutionStrategy` is public API (`EntityManagerOptions`, `EntityManager`
  constructor, `setUpdateConflictResolutionStrategy()`), and 2.0.0 is tagged. Its array shape
  must not change in 2.x.
- Soft-delete still uses `executeUpdateByTable`'s version parameters — they stay.

## Decisions

1. **Dead-code delete ships first, as its own commit.** No behaviour change.
2. **Typing is internal.** The public strategy contract stays array-shaped; `ChangeAggregator`
   converts `resolve()`'s output into typed values immediately. Typing the public contract is
   deferred to a 3.0 if a custom strategy ever needs it.
3. **Two readonly classes, no shared interface**, used as the union `EntityUpdate|MergedUpdate`.
4. **`MergedUpdate` keeps a single `entity`** (the first contributor), matching today. Sibling
   eviction in the cache already covers every slice on the row. `entity` is nullable: the 2.0.0
   merged shape `{table, set, where, whereValues}` has no `entity` key, so a 2.0.0-conformant
   custom strategy may omit it. A null `entity` skips second-level cache eviction, matching the
   pre-typing `isset($update['entity'])` behaviour.
5. **Malformed strategy output throws `UpdateConflictException`** naming the strategy class. No new
   exception type.
6. **Only `updates` is typed.** `aggregateChanges()` keeps its outer
   `array{inserts, updates, deletes, softDeletes}` shape.
7. **Classes live in `src/Modules/EntityManager/`** next to `DeferredVersionBump` — no Deptrac change.

## Work

### Commit 1 — delete dead code

- `MergeUpdateConflictResolutionStrategy`:
  - drop the input-side `isset($update['table'])` pass-through (`:15-19`);
  - drop `$versionBumpColumns` (`:39`) and its use in the empty-change check;
  - drop `versionBumpColumns` from the merged array;
  - drop the dedupe block and comment (`:66-72`).
- `ChangeSetExecutor.php:40`: drop `versionBumpColumns: $update['versionBumpColumns'] ?? []`.
- `UpdateConflictResolutionStrategy` docblock: `@param` = entity updates only;
  `@return` = entity updates or merged updates (no `versionBumpColumns`).
- `ChangeSetExecutor::execute()` docblock: drop `versionBumpColumns?`.

### Commit 2 — type the update list

- Add `final readonly class EntityUpdate(object $entity, array $changes)`.
- Add `final readonly class MergedUpdate(string $table, ?object $entity, array $set, string $where, array $whereValues)`.
- `ChangeAggregator::aggregateChanges()`: map `resolve()` output to
  `list<EntityUpdate|MergedUpdate>`; anything matching neither shape →
  `UpdateConflictException` naming `$this->updateConflictResolutionStrategy::class`.
- `ChangeSetExecutor::execute()`: `instanceof MergedUpdate` / `EntityUpdate` instead of `isset`.
- `EntityCacheCoordinator::invalidateSecondLevelCache()`: read `$update->entity`.
- Update docblocks on `aggregateChanges()`, `execute()`, `invalidateSecondLevelCache()`.

## Tests

- New (write first): a stub `UpdateConflictResolutionStrategy` returning a malformed shape →
  `aggregateChanges()` throws `UpdateConflictException`.
- `ChangeAggregatorTest:269`: `$result['updates'][0]['entity']` → `->entity`.
- Unchanged: `ThrowOnUpdateConflictStrategyTest` (public contract unchanged).
  `MergeUpdateConflictResolutionStrategyTest` is unchanged except that its two raw-table
  pass-through tests were removed with commit 1 (they covered the deleted input-side
  `isset($update['table'])` branch).
- Regression: `OptimisticLockingTest`, `tests/EdgeCases/SharedTableEntitiesTest.php` on MySQL and
  PostgreSQL.
- Gates: `composer cs:check`, `composer static:check`, `composer architecture:check`, `composer test`.

## Docs

- `CONTEXT.md` § Flush — done.
- No ADR (reversible). No CHANGELOG entry (no user-visible change).

## Out of scope

- Soft-delete version path and `executeUpdateByTable` version params — candidate 1.
- Duplicated row-identity logic in the strategies — candidate 3.
- Cache eviction before commit — candidate 6.
