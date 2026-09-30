---
title: Pagination & Filtering
description: Page through query results and apply global filters such as soft delete.
sidebar:
  order: 6
---

import { Tabs, TabItem, Aside } from '@astrojs/starlight/components';

Page through query results and apply global filters such as soft delete.

## Pagination strategies

<Tabs>
<TabItem label="Offset pagination">
Simple and useful for small or administrative lists:

```php
$qb->limit(10)->offset(20);
```

It can become expensive or unstable on large, frequently changing datasets.
</TabItem>
<TabItem label="Cursor pagination">
Uses the last seen ordered value instead of a numeric offset:

```php
$qb
    ->cursor($cursor)
    ->cursorLimit(10)
    ->orderBy('id', 'ASC');

$result = $qb->getCursorPaginatedResult();
```

<Aside type="caution">
	Use a unique or tie-broken ordering, such as `created_at` plus `id`, so records aren't skipped or duplicated across pages.
</Aside>
</TabItem>
</Tabs>

## Ordering

```php
$qb
    ->orderBy('created_at', 'DESC')
    ->orderBy('id', 'ASC');
```

Ordering matters for both user-facing list behavior and cursor stability.

## Soft delete filter

Register `SoftDeleteFilter` to automatically exclude records with a deleted marker such as `deleted_at`. Use `withoutFilter('soft_delete')` on a query builder when an administrative query intentionally needs to include soft-deleted records.

```php
#[SoftDeleteable(fieldName: 'deleted_at', columnName: 'deleted_at')]
class Customer
{
    #[Property(name: 'deleted_at', nullable: true)]
    public ?string $deleted_at = null;
}
```

## Common pitfalls

- Soft-deleted rows are hidden from primary-key `find()` calls as well as list queries.
- `withoutFilter('soft_delete')` applies to the query builder where it is called — it does not turn the filter off globally.
- Cursor pagination needs a unique or tie-broken order, such as timestamp plus `id`.
