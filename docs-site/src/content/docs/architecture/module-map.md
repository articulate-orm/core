---
title: Module Map
description: Where each piece of Articulate lives, and the dependency direction Deptrac enforces between them.
sidebar:
  order: 1
---

import { Aside } from '@astrojs/starlight/components';

| Module | Path | Purpose |
|--------|------|---------|
| EntityManager | `src/Modules/EntityManager/` | UnitOfWork, IdentityMap, hydrators, lifecycle callbacks, lazy-loading proxies |
| QueryBuilder | `src/Modules/QueryBuilder/` | Fluent SQL builder, WHERE clauses, keyset pagination, soft-delete filter |
| Database | `src/Modules/Database/` | Schema reader, type mappers per DB, schema comparator (diff engine) |
| Repository | `src/Modules/Repository/` | AbstractRepository, EntityRepository, criteria pattern |
| Migrations | `src/Modules/Migrations/` | Schema diff → migration SQL (MySQL & PostgreSQL generators) |
| Generators | `src/Modules/Generators/` | ID strategies: UUID v4/v7, ULID, AutoIncrement, Serial, Prefixed |
| Attributes | `src/Attributes/` | All PHP attributes + reflection wrappers (ReflectionEntity, ReflectionProperty, ReflectionRelation) |
| Schema | `src/Schema/` | EntityMetadata, EntityMetadataRegistry, naming conventions |
| Utils | `src/Utils/` | TypeRegistry, type converters (bool↔TINYINT, DateTime↔DATETIME, etc.) |
| Commands | `src/Commands/` | Symfony Console: DiffCommand, InitCommand, MigrateCommand, ValidateCommand, WarmMetadataCacheCommand |

## Schema comparator (diff engine)

`DatabaseSchemaComparator` in `src/Modules/Database/SchemaComparator/` compares live DB schema against entity attributes, using per-concern comparators:

- `ColumnComparator` — column type/nullability/default diffs
- `EntityTableComparator` — table-level diffs
- `ForeignKeyComparator`, `IndexComparator`, `MappingTableComparator`

Output feeds `MigrateCommand` and `DiffCommand`.

## Where new things go

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

<Aside type="tip">
	Always run `composer architecture:check` after adding a cross-module dependency — see [Boundaries & Conventions](/architecture/boundaries/).
</Aside>
