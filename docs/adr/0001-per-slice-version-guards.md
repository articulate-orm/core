# Per-slice version guards

## Status

accepted

## Context

Optimistic locking (1.x) treated a `#[Version]` column as row-wide: every
context-bounded entity ("slice") mapping a versioned table had to bump the
version on every write, either through its own `#[Version]` property or a
`#[VersionAware]` list of raw columns. A slice that only writes a cosmetic
field (e.g. `orders.alias`) still bumped `orders.version`, so a cosmetic write
raised spurious `OptimisticLockException`s in the slice that actually cares
about lost updates (e.g. `orders.status`). Critical flows were blocked by
non-critical ones.

## Decision

A `#[Version]` column guards only its **guard set** — the `#[Property]`
columns declared on the slice that owns it, minus the primary key. It is
bumped and checked only on writes through that slice. A slice touching none
of a guard set's columns emits no version SQL and cannot conflict with it.
Tables may carry several independent `#[Version]` columns, one per concern.

`#[VersionAware]` becomes a no-op acknowledgement marker: it has zero runtime
effect and exists only so `articulate:validate` can require a developer to
consciously opt in when a slice writes a column inside a sibling's guard set
without taking on that guard's lost-update detection. It names the version
columns it acknowledges. `articulate:validate` also takes a lenient flag that
downgrades the missing-acknowledgement error to a warning, for projects that
don't want every crossing declared. There is no "acknowledge everything on
this table" form of the attribute — that blunt waiver would also swallow
guards added to the table later.

A `#[Version]`-checking slice is never combined into a table-scoped merge
UPDATE: its `WHERE version = ?` check and `rowCount()`-based conflict
detection must run on its own entity-bound statement, so two versioned
slices dirty on the same row in one flush emit one UPDATE each. Only
non-versioned slices remain combinable.

`#[Version]` implies `#[Property]`.

This ships as 2.0.0 — `#[Version]` semantics and the `#[VersionAware]`
constructor both change, with no compatibility shim.

## Considered options

- **Per-column dirty tracking** (bump/check a version only when one of its
  guarded columns is actually dirty in a given UPDATE) — rejected: within a
  slice every non-PK column is guarded, so it collapses to the per-slice
  trigger anyway, at the cost of a more complex runtime.
- **Keep `#[VersionAware]` as a bump-only participant** — rejected: any slice
  that bumps a shared counter reintroduces the cross-flow blocking this
  change exists to remove.

## Consequences

- A future reader sees a version column that does not cover its whole table
  and `#[VersionAware]` attributes that generate no SQL; both are deliberate.
- Rival counters (two `#[Version]` columns with overlapping guard sets) are a
  validate error. Combined within a single flush they happen to behave, but
  across separate flushes or rows their independence is a lie and there is no
  known use case for the overlap.
- Runtime version resolution stays table-lookup-free; guard sets are a
  validate-time concern only.
