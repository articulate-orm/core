# Changelog

All notable changes to `articulate-orm/core` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

## [1.2.1]

- Patch release on the 1.2.x line.
- Package moved to the `articulate-orm` org and renamed `denisyu-1/articulate` → `articulate-orm/core`.

## [1.2.0]

- Optimistic locking (row-wide `#[Version]` / `#[VersionAware]` model, superseded by the
  per-slice rework above).
