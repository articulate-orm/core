---
title: Caching
description: Three independent PSR-6 cache layers — second-level cache, query result cache, and statement cache.
sidebar:
  order: 4
---

import { Aside, Tabs, TabItem } from '@astrojs/starlight/components';

Articulate has three independent cache layers, all using PSR-6 (`CacheItemPoolInterface`). Pass the same pool instance to share backend, or separate instances for isolation.

## Second-level cache

Cross-request entity cache. Survives beyond a single `EntityManager` instance.

```text
Request A: identity map miss → DB hit → entity stored in L2 cache
Request B: identity map miss → L2 cache hit → DB skipped entirely
Request C: identity map miss → L2 cache hit → DB skipped entirely
```

```php
$em = new EntityManager(
    $connection,
    resultCache: $cachePool,           // also backs L2 cache unless overridden
    secondLevelCacheTtl: 3600,
);

// Or with a dedicated L2 pool:
$em = new EntityManager(
    $connection,
    resultCache: $queryPool,
    secondLevelCache: $entityPool,     // separate backend for entity cache
    secondLevelCacheTtl: 3600,
);
```

`find()` checks the identity map first, then the L2 cache, then the database. On `flush()`, modified and deleted entity entries are evicted automatically — stale data is never served after a write. Eviction also covers **sibling** entity classes sharing the same table and primary key.

<Aside type="note">
	Second-level cache serves `find()` by class and primary key. It does **not** serve `findBy()`, query-builder `getResult()`, or chunked/list reads.
</Aside>

## Query result cache

Cache raw result sets from `QueryBuilder` queries — useful for read-heavy queries that don't change often.

```php
$users = $em->createQueryBuilder(User::class)
    ->from('users')
    ->where('status', 'active')
    ->enableResultCache(lifetime: 300, resultCacheId: 'active_users')
    ->getResult();
```

- Custom cache key via `resultCacheId`, or auto-generated from query shape + parameters.
- Locked queries (`FOR UPDATE`) are never cached.
- Call `disableResultCache()` to opt out per query.

## Statement cache

Caches compiled SQL strings (query structure, not results) — eliminates repeated SQL compilation for queries with the same shape but different parameter values.

```php
$em = new EntityManager($connection, statementCache: $cachePool);
```

Transparent — no per-query opt-in needed. Failures are silently ignored so a broken cache backend never breaks queries.

## Connection pooling

<Tabs>
<TabItem label="Persistent connections">
```php
$connection = new Connection(
    dsn: 'mysql:host=127.0.0.1;dbname=myapp',
    user: 'root',
    password: 'secret',
    persistent: true,
);
```
Skips TCP handshake and authentication overhead on each request. Pair with a pool-aware cache backend for full cross-request performance.
</TabItem>
<TabItem label="Cache TTL caveats">
Result cache can return stale aggregate rows until its TTL expires — treat `enableResultCache()` as an explicit staleness trade-off, not a correctness-neutral optimization.
</TabItem>
</Tabs>
