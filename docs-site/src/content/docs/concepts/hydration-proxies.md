---
title: Hydration & Proxies
description: The four hydrators and the runtime proxy system behind lazy loading.
sidebar:
  order: 3
---

import { CardGrid, Card, Aside } from '@astrojs/starlight/components';

## Hydrators

Four hydrators live in `src/Modules/EntityManager/Hydrators/`:

<CardGrid>
	<Card title="ObjectHydrator" icon="document">
		Full entity objects. The default hydration path.
	</Card>
	<Card title="ArrayHydrator" icon="seti:json">
		Raw arrays instead of entity objects.
	</Card>
	<Card title="ScalarHydrator" icon="seti:config">
		A single scalar value — useful for `count()`/aggregate-style queries.
	</Card>
	<Card title="PartialHydrator" icon="document">
		A subset of an entity's properties, for reporting or read-only projections.
	</Card>
</CardGrid>

`LazyLoadingHydrator` wraps `ObjectHydrator` with proxy injection for deferred relation loading.

## Proxy system

`ProxyGenerator` generates PHP proxy classes **at runtime** for lazy loading. Proxies intercept property access and trigger `LazyLoadingHydrator` on first touch. Generated proxies are cached in the configured proxy directory.

<Aside type="caution" title="Known limitation">
	Lazy relation proxies cannot currently be flushed safely in the installed dependency version used by the demo — prefer explicit `loadRelation($entity, $relationName)` when a relation needs to be shown or written through. See [Known Limitations](/guides/known-limitations/).
</Aside>

## Choosing a hydration strategy

Use `PartialHydrator` or `ScalarHydrator` when full entity hydration is unnecessary — reporting paths, aggregate output, and read-only projections all benefit. Note that normal aggregate or specific-column query-builder selects currently force raw array hydration before custom hydrators can run; see [Known Limitations](/guides/known-limitations/) for the exact caveats.
