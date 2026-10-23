# Articulate Flush

How pending entity changes become row writes at flush time, with optimistic locking. Several entity classes (slices) may map to the same table.

## Language

**Slice**:
One entity class mapped to a table that other entity classes also map to.
_Avoid_: context entity, view

**Entity update**:
A pending write of one row tied to one entity, so that slice's own version check can apply.
_Avoid_: entity-bound write, entity-bound update

**Merged update**:
A pending write of one row combining the changes of several sibling slices. It never carries version semantics.
_Avoid_: combined update, merged write

**Versioned row write**:
An entity update (UPDATE or soft-delete) that bumps and checks its slice's own `#[Version]` column; zero matched rows is a lost update.
_Avoid_: locked update, version bump

**Lost update**:
A versioned row write that matches no row, because the tracked version is stale or the row is gone.
_Avoid_: lock conflict
