---
title: Context-Bounded Entities
description: Multiple entity classes can map to the same database table, each exposing only what a bounded context needs.
sidebar:
  order: 1
---

import { Aside, Card, CardGrid, Tabs, TabItem } from '@astrojs/starlight/components';

Multiple entity classes can point to the **same database table**, each exposing only the fields and relationships needed for that context. Articulate merges compatible column definitions and validates for conflicts. This is the primary differentiator from standard ORMs.

## The problem

A `users` table may be touched by authentication, administration, billing, public APIs, reporting, and background workers. Those contexts do not need the same fields, relations, invariants, or lifecycle behavior. A single shared `User` entity gradually becomes a coupling point between modules.

<Tabs>
<TabItem label="Before: one shared entity">
```php
#[Entity]
class User
{
    public int $id;
    public string $login;
    public string $password;
    public string $name;
    public array $phones;  // Auth doesn't need this
    public array $groups;  // Auth doesn't need this
    public Cart $cart;     // Auth doesn't need this
}

// Auth: loads full user + all relations
$user = $userRepo->find($id);
return $auth->validate($user->login, $user->password);
```
</TabItem>
<TabItem label="After: one entity per context">
```php
#[Entity(tableName: 'user')]
class LoginUser
{
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $login;

    #[Property]
    public string $password;
}

#[Entity]
class User
{
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[OneToMany(ownedBy: 'user', targetEntity: Phone::class)]
    public array $phones;

    #[OneToOne(targetEntity: Cart::class, referencedBy: 'user')]
    public Cart $cart;
}

// Auth: loads only id, login, password
$loginUser = $em->getRepository(LoginUser::class)->find($id);
return $auth->validate($loginUser->login, $loginUser->password);
```
</TabItem>
</Tabs>

## Read-only entities

Mark a context-bounded entity as read-only when it intentionally omits required columns — for example, a `LoginUser` that exposes only `login` and `password` from a `users` table that has many more non-nullable columns.

```php
#[Entity(tableName: 'user', readOnly: true)]
class LoginUser
{
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $login;

    #[Property]
    public string $password;
}

// find() and QueryBuilder work normally:
$loginUser = $em->getRepository(LoginUser::class)->find($id);
$auth->validate($loginUser->login, $loginUser->password);

// persist() and remove() throw ReadOnlyEntityException:
$em->persist($loginUser); // throws
```

<Aside type="note">
	`ReadOnlyEntityException` is thrown at `persist()` and `remove()` — **before** any SQL is built.
</Aside>

## Cross-entity remove propagation

When `remove()` is called on an entity, `UnitOfWork` automatically marks all other **MANAGED** entities in the same `IdentityMap` that share the same table and primary key as REMOVED. Only one `DELETE` is issued — sibling entities are just dropped from tracking, preventing ghost `IdentityMap` reads and phantom `UPDATE` calls on the deleted row.

<Aside type="caution" title="Lifecycle callbacks don't propagate">
	`preRemove` / `postRemove` are only invoked on the entity you explicitly removed, never on its siblings.
</Aside>

## When it fits

<CardGrid>
	<Card title="Good fit" icon="approve-check">
		Different bounded contexts need different views of the same data; adding a relation for one workflow shouldn't affect every other workflow; long-running processes need tighter control over tracked entities.
	</Card>
	<Card title="Probably not needed" icon="information">
		Your application has one stable entity model per table and your current ORM already handles that well — Articulate still fits, but likely won't solve a meaningful problem for you.
	</Card>
</CardGrid>

## See also

- [Entity Mapping guide](/guides/entity-mapping/) — the `#[Entity]`, `#[Property]`, and index attributes in practice.
- [Optimistic Locking guide](/guides/optimistic-locking/) — how version guards stay per-slice instead of row-wide.
