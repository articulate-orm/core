---
title: Query Builder
description: Build database queries with filters, joins, aggregates, subqueries, and reusable Criteria.
sidebar:
  order: 5
---

import { Aside } from '@astrojs/starlight/components';

Build database queries with filters, joins, aggregates, subqueries, and reusable Criteria objects.

## Basic usage

```php
$qb = $entityManager->createQueryBuilder(User::class);

$users = $qb
    ->select('*')
    ->where('status', 'active')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->getResult();
```

## Where clauses

- `where($field, $value)` for equality.
- `whereIn($field, $values)` for `IN (...)`.
- `whereNull($field)` and `whereNotNull($field)` for null checks.
- `whereExists($callback)` for subqueries.
- `whereRaw($sql, ...$params)` for escape-hatch SQL with bound parameters.

## Joins and aggregates

Use `join`, `leftJoin`, `rightJoin`, and `crossJoin` for table joins. Use `count`, `sum`, `avg`, `max`, and `min` for aggregate selects.

## Criteria

Implement `CriteriaInterface` when a filter should be reusable across repositories or commands, then apply it to a builder.

```php
$orders = $this->entityManager
    ->createQueryBuilder(Order::class)
    ->whereNull('shipped_at')
    ->orderBy('placed_at', 'DESC')
    ->getResult();
```

Use `whereRaw()` only with bound parameters:

```php
$qb->whereRaw('total > ?', [100]);
```

## Batch reads

For large result sets, prefer bounded batches and clear the `EntityManager` between batches so the identity map does not grow without limit. See [Performance](/guides/performance/) for memory behavior.

## Common pitfalls

<Aside type="caution">
- Use `whereNull()` for SQL null checks. `where('column', null)` currently compiles as `column = ?` with a null parameter instead — see [Known Limitations](/guides/known-limitations/).
- Empty `whereIn()` input should be treated intentionally.
- Never concatenate user input into `whereRaw()` — always bind parameters.
- `QueryBuilder::chunk()` is documented but not yet available in every installed dependency version — batch demos fall back to limit/offset loops.
</Aside>
