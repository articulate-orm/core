---
title: Performance
description: Identity map, result cache, second-level cache, query logging, partial hydration, and batch iteration.
sidebar:
  order: 11
---

import { Aside } from '@astrojs/starlight/components';

Understand the runtime behavior that matters once your usage moves beyond small CRUD flows.

## Identity map

`EntityManager` keeps an in-memory identity map. Loading the same entity class and primary key twice returns the **same PHP object instance** until the manager is cleared.

This avoids duplicate objects for the same row, but it also means long-running imports should clear the manager periodically.

## Result cache

```php
$qb->enableResultCache($lifetime, $cacheId);
```

Result cache stores query results. Locked queries are never cached.

## Second-level cache

```php
$em = new EntityManager($connection, secondLevelCache: $pool);
```

Second-level cache stores raw entity row data for `find()` lookups by entity class and primary key. It survives `EntityManager::clear()`, unlike the identity map.

When a flush updates, deletes, or soft-deletes an entity, Articulate evicts cache entries for every mapped entity class that shares the same table and primary key — keeping same-row projections consistent after writes.

<Aside type="note">
	Second-level cache does **not** serve list/query paths such as `findBy()`, query-builder `getResult()`, or chunked batch reads.
</Aside>

## Query logging

Implement `QueryLoggerInterface` to profile query count, SQL text, and cache effects.

## Partial hydration

Use `PartialHydrator` or `ScalarHydrator` when full entity hydration is unnecessary — reporting paths, aggregate output, and read-only projections all benefit. See [Hydration & Proxies](/concepts/hydration-proxies/).

## Batch iteration

Avoid loading very large datasets with a single `getResult()` call. Process rows in bounded batches and call `$entityManager->clear()` between batches when the identity map would otherwise grow too large.

```php
$batch = $entityManager
    ->createQueryBuilder(OrderSnapshot::class)
    ->orderBy('placed_at', 'ASC')
    ->limit($chunkSize)
    ->offset($offset)
    ->getResult();

$entityManager->clear();
```

## Common pitfalls

<Aside type="caution">
- Second-level cache helps `find()` by primary key, not list queries.
- Result cache can return stale aggregate rows until its TTL expires.
- Scalar and partial hydration have some current caveats — see [Known Limitations](/guides/known-limitations/).
</Aside>
