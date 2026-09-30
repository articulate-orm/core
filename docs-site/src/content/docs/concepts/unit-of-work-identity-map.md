---
title: Unit of Work & Identity Map
description: How EntityManager tracks entity state, and why each context owns its own identity map.
sidebar:
  order: 2
---

import { Aside, Steps } from '@astrojs/starlight/components';

`EntityManager` tracks entity state (`NEW` → `MANAGED` → `REMOVED`) via `UnitOfWork`. Changes are collected by a `ChangeAggregator` and flushed as a batch. **Each context (EntityManager instance) has its own `IdentityMap`** — no process-wide singletons.

## Lifecycle of a flush

<Steps>
1. Entities are `persist()`-ed or loaded via `find()` / a repository, entering the `IdentityMap` as `MANAGED`.
2. Property mutations are tracked by the `ChangeAggregator` against a change-tracking snapshot taken at load/persist time.
3. `flush()` wraps all pending work in **one transaction** and computes the minimal set of `INSERT` / `UPDATE` / `DELETE` statements.
4. On success, the transaction commits and in-memory state (generated IDs, bumped version columns) is reconciled with what was written.
5. On any throw, the transaction rolls back. In-memory mutations that happened *inside* the flush loop must be revertable, or the entity is left poisoned relative to the (reverted) database state.
</Steps>

<Aside type="danger" title="Transaction + in-memory state coherence">
	Code that eagerly mutates entity property state (bumping a version int, setting generated ids) **inside** the flush loop can leave objects poisoned after a rollback — DB reverted, memory not — which breaks the retry the feature exists to enable. This is a recurring review focus area in this codebase.
</Aside>

## Memory-efficient unit of work

- Clear entities from memory that are no longer needed within specific operations via `$em->clear()`.
- Different units of work can track their own entities independently.
- `EntityManager` combines all unit-of-work changes into minimal database queries during flush.

Useful for processing large datasets, complex business operations spanning multiple contexts, and long-running processes with varying entity lifecycles.

## Read replicas via separate contexts

There's no built-in read/write routing — intentionally. Let infrastructure handle it (PgBouncer, ProxySQL, RDS Proxy), or use the per-context design directly:

```php
// Write context → primary
$primary = new EntityManager($primaryConnection, ...);
$primary->persist($entity);
$primary->flush();

// Read context → replica
$replica = new EntityManager($replicaConnection, ...);
$users = $replica->findAll(User::class);
```

Each `EntityManager` owns its `IdentityMap` and `UnitOfWork` — calling `flush()` on a replica-backed instance is a design smell, not a supported write path.

## See also

- [Performance guide](/guides/performance/) — identity map, second-level cache, and batch iteration in practice.
- [Optimistic Locking guide](/guides/optimistic-locking/) — recovery after a flush conflict without a poisoned unit of work.
