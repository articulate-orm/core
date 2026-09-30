---
title: Entity Mapping
description: Map plain PHP classes to database tables with PHP 8 attributes.
sidebar:
  order: 2
---

import { Aside, Card, CardGrid } from '@astrojs/starlight/components';

Map plain PHP classes to database tables with PHP 8 attributes — no XML, no YAML, no annotations.

## The `#[Entity]` attribute

```php
#[Entity]
#[Entity(tableName: 'custom_table')]
```

Use `#[Entity]` for the default table name, or `#[Entity(tableName: '...')]` when the physical table name should be explicit.

## Property attributes

- `#[PrimaryKey]` marks the primary key column.
- `#[AutoIncrement]` delegates integer ID assignment to the database.
- `#[Property]` maps a PHP property to a database column.
- `#[Property(name: 'created_at', type: 'datetime', nullable: true, maxLength: 255)]` customizes the physical column metadata.

## Indexes

```php
#[Index(['email'], unique: true, concurrent: true)]
#[Index(['created_at', 'status'], concurrent: false)]
```

Indexes are consumed by schema diffing and migration generation.

<Aside type="caution">
	PostgreSQL `CREATE INDEX CONCURRENTLY` cannot run inside a transaction, so migrations that create concurrent indexes must disable transactional execution — see [Migrations](/guides/migrations/#transactional-migrations).
</Aside>

`#[Index]` takes `fields` — PHP **property names**, not column names — keeping index definitions coupled to the entity model. Rename a property alongside its column and PHP tooling catches the broken reference; raw column strings would silently diverge.

## Same-table projections

Different entity classes can map to the same table. This is used for read models such as customer summaries and analytics snapshots where a feature only needs a subset of columns.

```php
#[Entity(tableName: 'products')]
#[Index(['sku'], unique: true, name: 'uniq_products_sku')]
final class Product
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    #[Property(name: 'product_name', maxLength: 160)]
    public string $name;
}
```

## MySQL table options

Articulate deliberately does **not** append `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=...` to generated `CREATE TABLE` statements — storage engine and character set are deployment concerns, not schema concerns, and configuring them once at the server/database level (`CREATE DATABASE ... CHARACTER SET utf8mb4`) is more correct than hardcoding a default that may not fit every environment.

## Common pitfalls

<CardGrid>
	<Card title="Required property ↔ required column" icon="warning">
		A required PHP property should match a required database column, or the database raises the final constraint error at flush time.
	</Card>
	<Card title="Explicit column name mapping" icon="warning">
		When the PHP property name differs from the column name, set `#[Property(name: '...')]` explicitly.
	</Card>
	<Card title="Projections are independent objects" icon="warning">
		Same-table projections are separate entity classes. Loading `Customer` and `CustomerSummary` for the same row gives two independent PHP objects.
	</Card>
</CardGrid>
