---
title: Migrations
description: Generate and apply schema changes from Articulate entity metadata.
sidebar:
  order: 3
---

import { Steps, Aside } from '@astrojs/starlight/components';

Generate and apply schema changes from Articulate entity metadata.

## Commands

- `articulate:init` creates the migration tracking table.
- `articulate:diff` generates migrations from the entity/database schema diff.
- `articulate:migrate` runs pending migrations.

## Configuration

```yaml title="config/services.yaml"
parameters:
    articulate_entities_path: 'src'
    articulate_migrations_path: '%env(resolve:ARTICULATE_MIGRATIONS_PATH)%'
    articulate_migrations_namespace: 'App\Migrations'
```

Keep driver-specific migrations in separate folders (e.g. `migrations/mysql` and `migrations/pgsql`) and set `ARTICULATE_MIGRATIONS_PATH` to the active one.

## Clean database workflow

<Steps>
1. Start services: `docker compose up -d`.
2. Run `articulate:init`.
3. Run `articulate:migrate`.
</Steps>

If migrations are already checked in, `articulate:diff` isn't required from a clean checkout. Run `articulate:diff` when you change entity metadata and want Articulate to generate new migration files from the schema difference. From a clean database, the first diff can generate migrations for all mapped entity tables — later diffs should only contain the delta.

## Transactional migrations

Migrations run inside a transaction by default. Override `isTransactional()` when a migration contains database operations that must run outside a transaction, such as PostgreSQL `CREATE INDEX CONCURRENTLY`.

```php
protected function isTransactional(): bool
{
    return false;
}
```

## Common pitfalls

<Aside type="caution">
- Confirm `ARTICULATE_MIGRATIONS_PATH` points at the driver you're using before running diff or migrate.
- Do not commit generated diffs without reviewing them against the intended entity change.
- PostgreSQL concurrent index creation requires a non-transactional migration.
- Polymorphic pivot schemas currently have comparison caveats — see [Known Limitations](/guides/known-limitations/).
</Aside>
