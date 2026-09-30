---
title: Lifecycle Callbacks
description: Run entity methods around persistence, update, removal, and hydration events.
sidebar:
  order: 7
---

import { CardGrid, Card, Aside } from '@astrojs/starlight/components';

Run entity methods around persistence, update, removal, and hydration events.

## Attributes

```php
#[PrePersist]
public function onPrePersist(): void
{
}

#[PostPersist]
public function onPostPersist(): void
{
}
```

Available callback attributes:

<CardGrid>
	<Card title="#[PrePersist]" icon="pencil" />
	<Card title="#[PostPersist]" icon="pencil" />
	<Card title="#[PreUpdate]" icon="pencil" />
	<Card title="#[PostUpdate]" icon="pencil" />
	<Card title="#[PreRemove]" icon="pencil" />
	<Card title="#[PostRemove]" icon="pencil" />
	<Card title="#[PostLoad]" icon="pencil" />
</CardGrid>

## Use cases

- Set `created_at` or `updated_at` timestamps.
- Write audit records after persistence.
- Validate entity state before flush.
- Normalize values after loading from the database.
- Mark soft-delete columns before removal.

```php
#[PreUpdate]
public function onPreUpdate(): void
{
    $this->updated_at = self::now();
}

#[PostLoad]
public function onPostLoad(): void
{
    $this->record('PostLoad');
}
```

## Common pitfalls

<Aside type="caution">
- Throwing from `PrePersist` prevents the entity from being written.
- `PostLoad` can run on `find()`, query-builder hydration, **and** explicit relation loading — don't assume it only fires once per object lifetime.
- Keep callback side effects narrow so command/request output stays understandable.
</Aside>
