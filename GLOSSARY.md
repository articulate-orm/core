# Articulate

A context-bounded ORM: several entity classes, each scoped to one bounded context, map to the same database table.

## Language

### Slices

**Slice**:
An entity class that maps a table for one bounded context; one table may have many slices.
_Avoid_: context-bounded entity, view entity, partial entity

**Sibling**:
Another slice that maps the same table.
_Avoid_: twin, alias class

### Optimistic locking

**Version column**:
A counter column that a slice owns through its own `#[Version]` property, and that protects that slice's writes against lost updates.
_Avoid_: lock column, revision

**Guard set**:
The columns a version column protects: the persisted columns of the slice that owns it, minus the primary key and the version column itself.
_Avoid_: covered columns, protected columns

**Acknowledgement**:
A slice's declaration that it writes into a sibling's guard set without taking part in that guard's lost-update detection.
_Avoid_: opt-out, waiver

**Rival counters**:
Two different version columns on one table whose guard sets overlap.
_Avoid_: competing versions, duplicate versions

**Unacknowledged guarded write**:
A slice persisting a column in a sibling's guard set with neither its own `#[Version]` over that version column nor an acknowledgement of it.

**Plain version column**:
A sibling's version column mapped by a slice as an ordinary property, letting it overwrite the counter.
