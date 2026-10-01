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

- Same-table projections are supported, but each entity class is still an independent identity-map context — two classes mapping the same row are different PHP objects.
- Projection entities still need primary-key metadata for clean hydration, identity-map registration, `find()`, and second-level cache behavior.

## Relations

- Prefer explicit `loadRelation()` over lazy relation proxies when a relation needs to be written through in the same flush — it's the clearer data path, not a workaround for a correctness bug.
- Relation-owned foreign key columns should not also be mapped as scalar properties on the same entity.

## Query builder

- `whereRaw()` should always use bound parameters, e.g. `whereRaw('total > ?', [100])`; concatenating user input is unsafe.

## Migrations

- A clean checkout with checked-in migrations can run `articulate:migrate` without first running `articulate:diff`.
- `articulate:diff` may expose current schema-comparison gaps around polymorphic pivot columns.
- A polymorphic pivot may need to store `taggable_id` as `VARCHAR(36)` if it must hold both integer and UUID identifiers.

## Hydration

- Normal aggregate or specific-column query-builder selects force raw array hydration before custom hydrators can run.

## Caching

- Second-level cache serves `find()` by class and primary key. It does not serve `findBy()`, query-builder `getResult()`, or chunked/list reads.
- Writes evict sibling classes that share the same table and primary key, but the in-memory identity map does not synchronize different projection objects already loaded in the same manager.
- Result cache can return stale aggregate data inside its TTL.
