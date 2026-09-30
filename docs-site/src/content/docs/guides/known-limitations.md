---
title: Known Limitations
description: Current library gaps and workarounds, grouped by area — rechecked as Articulate evolves.
sidebar:
  order: 12
---

import { Aside } from '@astrojs/starlight/components';

This page tracks current library behavior and workarounds that should be rechecked as Articulate evolves. Other guides link here when a limitation affects the feature they describe.

<Aside type="tip">
	None of these are "won't fix" — they're points-in-time gaps worth checking against the [Changelog](/reference/changelog/) before you route around them.
</Aside>

## Mapping

- Snake_case fields sometimes need explicit `#[Property(name: ...)]` mappings until hydrator fallback behavior is rechecked.
- Same-table projections are supported, but each entity class is still an independent identity-map context — two classes mapping the same row are different PHP objects.
- Projection entities still need primary-key metadata for clean hydration, identity-map registration, `find()`, and second-level cache behavior.

## Relations

- Lazy relation proxies cannot currently be flushed safely in some dependency configurations — prefer explicit `loadRelation()` when a relation needs to be shown or written through.
- `EntityManager::loadRelation()` currently returns `null` for `MorphToMany` and `MorphedByMany` relation objects — query the pivot table directly as a workaround.
- Relation-owned foreign key columns should not also be mapped as scalar properties on the same entity.

## Query builder

- `where('column', null)` currently compiles as `column = ?` with a null parameter. Use `whereNull('column')` or `whereNotNull('column')` instead.
- `QueryBuilder::chunk()` is documented but may be unavailable in some installed dependency versions — use limit/offset loops for batch reads instead.
- `whereRaw()` should always use bound parameters, e.g. `whereRaw('total > ?', [100])`; concatenating user input is unsafe.

## Migrations

- A clean checkout with checked-in migrations can run `articulate:migrate` without first running `articulate:diff`.
- `articulate:diff` may expose current schema-comparison gaps around polymorphic pivot columns.
- A polymorphic pivot may need to store `taggable_id` as `VARCHAR(36)` if it must hold both integer and UUID identifiers — and current checked-in schemas can carry a required technical `id` column even though the natural pivot key is `taggable_type`, `taggable_id`, and `tag_id`.

## Hydration

- Normal aggregate or specific-column query-builder selects force raw array hydration before custom hydrators can run.
- `ScalarHydrator` currently returns scalar values that can still be sent to Unit of Work registration, causing type errors in some paths.
- `PartialHydrator` delegates through object hydration in a way that can temporarily register an empty-id entity before partial fields are applied.

## Caching

- Second-level cache serves `find()` by class and primary key. It does not serve `findBy()`, query-builder `getResult()`, or chunked/list reads.
- Writes evict sibling classes that share the same table and primary key, but the in-memory identity map does not synchronize different projection objects already loaded in the same manager.
- Result cache can return stale aggregate data inside its TTL.
