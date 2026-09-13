# Changelog

All notable changes to `articulate-orm/core` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed — Optimistic locking: per-slice version guards (BREAKING)

Optimistic locking moves from a **row-wide** version model to a **per-slice** one.
See `docs/adr/0001-per-slice-version-guards.md`.

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

### Removed

- `EntityMetadata::getCheckedVersionColumns()` and
  `EntityMetadataRegistry::getVersionColumnsForTable()` — bump list and check list are now
  identical, so both collapse into `EntityMetadata::getVersionColumns()` (zero or one column).
- The metadata-build `\InvalidArgumentException` thrown when a column appeared in both a
  class's own `#[Version]` and its own `#[VersionAware]` list — that combination is now
  merely redundant, since `#[VersionAware]` is inert.
- Combined-write of multiple `#[Version]`-checking slices into a single UPDATE. Independent
  bounded contexts each keep their own entity-bound `WHERE version = ?` check.

### Added

- `EntityMetadata::getGuardSet()` and `EntityMetadata::getAcknowledgedVersionColumns()` —
  validate-time helpers; no runtime path reads them.
- `ManagedVersionColumnException` — thrown at flush time when a `#[Version]` column is
  assigned manually. The column is ORM-managed (server-side `col = col + 1` bump, checked
  in `WHERE` against the tracked value), so a hand-written value is rejected rather than
  silently dropped.

### Fixed

- Manual assignment to a `#[Version]` column no longer silently desyncs the lock check or
  duplicates the SET target (a hard error on PostgreSQL): `QueryExecutor` now throws
  `ManagedVersionColumnException` when a version column appears in the change set.

### Migrating from 1.x

`#[Version]` and `#[VersionAware]` semantics both change with no compatibility shim
(hence the major bump):

- Drop any `#[Property]` paired with `#[Version]` on the same property (now redundant, still
  legal).
- A `#[VersionAware]` list that was relied on to **bump** a sibling's version column no longer
  does so — remodel that flow as its own `#[Version]` slice if it needs lost-update detection,
  or keep `#[VersionAware]` purely as a validate-time acknowledgement.
- Run `articulate:validate` (optionally `--lenient`) to surface rival counters and unacknowledged
  guard-set crossings under the new rules.

## [1.2.1]

- Patch release on the 1.2.x line.

## [1.2.0]

- Optimistic locking (row-wide `#[Version]` / `#[VersionAware]` model, superseded by the
  per-slice rework above).
- Package moved to the `articulate-orm` org and renamed `denisyu-1/articulate` →
  `articulate-orm/core`.

[Unreleased]: https://github.com/articulate-orm/core/compare/1.2.1...HEAD
[1.2.1]: https://github.com/articulate-orm/core/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/articulate-orm/core/releases/tag/1.2.0
