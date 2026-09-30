---
title: Relationships
description: Define associations between mapped entities, from simple ownership to polymorphic links.
sidebar:
  order: 4
---

import { Aside, CardGrid, Card } from '@astrojs/starlight/components';

Define associations between mapped entities, from simple ownership to polymorphic links.

## Association types

<CardGrid>
	<Card title="#[OneToOne]" icon="right-arrow">Maps one entity to exactly one related entity.</Card>
	<Card title="#[OneToMany]" icon="right-arrow">Maps one parent to many children.</Card>
	<Card title="#[ManyToOne]" icon="right-arrow">Maps many children to one parent.</Card>
	<Card title="#[ManyToMany]" icon="right-arrow">Maps both sides through a pivot table.</Card>
</CardGrid>

<Aside type="caution">
	Relation-owned foreign key columns should be mapped by the relation only. Do not map the same physical column as both a scalar `#[Property]` and a relation on the same entity.
</Aside>

## Polymorphic relations

Articulate also exposes polymorphic relation attributes: `MorphTo`, `MorphOne`, `MorphMany`, `MorphToMany`, `MorphedByMany`.

```php
use Articulate\Attributes\Relations\MorphedByMany;
use Articulate\Attributes\Relations\MorphToMany;
use Articulate\Attributes\Relations\MorphTypeRegistry;
use Articulate\Modules\EntityManager\Collection;

MorphTypeRegistry::register(TaggableOrder::class, 'order');
MorphTypeRegistry::register(TaggableCustomer::class, 'customer');

#[Entity(tableName: 'orders')]
class TaggableOrder
{
    #[PrimaryKey]
    public int $id;

    #[MorphToMany(targetEntity: Tag::class, name: 'taggable', targetIdColumn: 'tag_id')]
    public Collection $tags;
}

#[Entity(tableName: 'tags')]
class Tag
{
    #[PrimaryKey]
    public int $id;

    #[MorphedByMany(targetEntity: TaggableOrder::class, name: 'taggable', targetIdColumn: 'tag_id')]
    public array $orders;
}

$order->tags->add($tag);
$em->flush(); // inserts into taggables using taggable_type = 'order'
```

The pivot table uses `{name}_type`, `{name}_id`, and the target id column:

```sql
taggables(taggable_type, taggable_id, tag_id)
```

Registered morph aliases are used for owning and inverse relation loading. If no alias is registered, Articulate falls back to storing and loading the full entity class name. When generating a polymorphic pivot schema, Articulate uses the composite key (`taggable_type`, `taggable_id`, `tag_id`) as the relation identity — a separate technical `id` column is not required for collection loading or persistence.

## Loading

Relations can be loaded during hydration depending on metadata and query path. Use `loadRelation($entity, $relationName)` when a relation needs to be loaded explicitly.

```php
#[ManyToOne(targetEntity: Customer::class, column: 'customer_id', nullable: false)]
public ?Customer $customer = null;

#[OneToMany(ownedBy: 'order', targetEntity: OrderItem::class, lazy: true)]
public array|Collection $items = [];
```

## Common pitfalls

- Map a foreign key column either as a relation or as a scalar property on the same entity, not both.
- Prefer explicit `loadRelation()` when relation loading behavior is the concept being shown or when lazy proxies aren't safe to flush — see [Known Limitations](/guides/known-limitations/).
- Polymorphic many-to-many relation loading via `loadRelation()` currently has gaps for `MorphToMany` / `MorphedByMany` — query the pivot table directly as a workaround.
