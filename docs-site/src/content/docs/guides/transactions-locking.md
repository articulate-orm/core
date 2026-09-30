---
title: Transactions & Locking
description: Use transactions and row-level locks when writes must be consistent across multiple queries.
sidebar:
  order: 9
---

import { Aside } from '@astrojs/starlight/components';

Use transactions and row-level locks when writes must be consistent across multiple queries.

## Transactional wrapper

```php
$entityManager->transactional(function (EntityManager $em) use ($entity) {
    $em->persist($entity);
    $em->flush();

    return $entity;
});
```

The wrapper commits when the callback returns and rolls back when the callback throws.

## Manual control

- `beginTransaction()` starts a transaction.
- `commit()` flushes and commits.
- `rollback()` rolls back pending database work.

Manual control is useful when a command needs to demonstrate intermediate failure states or lock behavior.

## Locking

Use `lock()` on the query builder for `SELECT ... FOR UPDATE`:

```php
$stock = $entityManager
    ->createQueryBuilder(StockLock::class)
    ->where('product_id', $productId)
    ->lock()
    ->getSingleResult();
```

<Aside type="danger">
	Locks require an active transaction. Calling `lock()` outside one raises a transaction-required error.
</Aside>

An inventory-decrement flow locks stock rows before decrementing:

```php
$stock = $entityManager
    ->createQueryBuilder(StockLock::class)
    ->where('product_id', $productId)
    ->lock()
    ->getSingleResult();
```

## Common pitfalls

- Calling `lock()` outside an active transaction raises a transaction-required error.
- Acquire multiple locks in a deterministic order to reduce deadlock risk.
- Keep transaction callbacks small; long-running work should happen before or after the transaction where possible.

## See also

Pessimistic locking (`SELECT ... FOR UPDATE`) blocks other writers for the duration of a transaction. Contrast with [Optimistic Locking](/guides/optimistic-locking/), which never locks and instead detects conflicts at write time.
