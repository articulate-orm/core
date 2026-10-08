# AI.md — Articulate for AI coding agents

This file is for AI assistants (Claude Code, Cursor, Copilot, Codex, etc.)
helping a developer **use** the `articulate-orm/core` library in their own
PHP application. Point your agent at this file when Articulate is a
dependency in the project you're working on.

> This is a *consumer* guide. If you are instead contributing to Articulate
> itself (the `core` repo), read `CLAUDE.md` in the repo root — it covers
> module layout, QA tooling, and architectural boundaries, none of which a
> library consumer needs.

## What Articulate is

A PHP 8.4+ ORM where **multiple narrow entity classes can map to the same
physical database table** — the "context-bounded entity" pattern. Example:
`LoginUser` (id, login, password) and `BillingCustomer` (id, name, email)
can both map to the same `users` table, each exposing only the columns its
bounded context needs. This is the thing that makes Articulate different
from Doctrine/Eloquent-style ORMs — keep it in mind when suggesting designs.

Other baseline facts:
- Attribute-based mapping only (`#[Entity]`, `#[Property]`, `#[PrimaryKey]`,
  `#[OneToMany]`, `#[ManyToMany]`, etc.) — no XML/YAML/annotations.
- Each `EntityManager` owns its own `IdentityMap` + `UnitOfWork` — no
  process-wide singletons, no global entity registry.
- First-class support for MySQL 8.0 and PostgreSQL 15.
- `composer require articulate-orm/core`.

## Quick Start (copy-paste should just work)

```php
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;

#[Entity]
class User
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public string $email;
}

$connection = new Connection('mysql:host=127.0.0.1;dbname=myapp', 'user', 'password');
$em = new EntityManager($connection);

$user = new User();
$user->name = 'Jane';
$user->email = 'jane@example.com';
$em->persist($user);
$em->flush();

$user = $em->getRepository(User::class)->find($user->id);
```

## Core concepts an agent must get right

1. **Context-bounded entities** — before modeling a table as one big entity
   class, ask whether different parts of the app (auth, billing, admin,
   public API) actually need different fields/relations from it. If so,
   model separate classes pointing at the same `tableName`, not one god
   entity. See README "Before / After" section for the canonical example.

2. **Read-only entities** — `#[Entity(tableName: 'user', readOnly: true)]`
   when a context-bounded class intentionally omits required/non-nullable
   columns from that table. `find()`/`QueryBuilder` work normally;
   `persist()`/`remove()` throw `ReadOnlyEntityException` before any SQL
   runs.

3. **Optimistic locking is per-class, not per-table.** Use `#[Version]` on
   the one class that should bump/check a version column. If a *sibling*
   class (same table, different narrow entity) also writes a column inside
   that guard set, it must declare `#[VersionAware(['version'])]` to
   acknowledge it — otherwise `vendor/bin/articulate validate` (run it in
   CI) flags it as a gap. Never assign to a `#[Version]` property manually
   — it's ORM-managed and throws `ManagedVersionColumnException` if you try.
   Don't write the same row through two different `#[Version]`-checking
   classes in one flush — the second write self-conflicts.

4. **`EntityManager` instances are NOT shared/global.** Each one has its
   own identity map and unit of work. For read-replica setups, use a
   separate `EntityManager` per connection (primary for writes, replica for
   reads) rather than trying to route inside one instance.

5. **Cross-entity remove propagation.** Calling `remove()` on one entity
   automatically marks every other MANAGED sibling entity (same table +
   same primary key, tracked in the same `IdentityMap`) as REMOVED too —
   only one `DELETE` is issued. Lifecycle hooks (`preRemove`/`postRemove`)
   only fire on the entity you explicitly removed, not its siblings.

6. **Three independent PSR-6 cache layers** — second-level (cross-request
   entity cache), query result cache, statement cache. All optional, all
   take any `CacheItemPoolInterface`. Don't assume caching is on by default.

7. **MySQL table options (ENGINE/CHARSET/COLLATE) are deliberately NOT
   generated** by migrations — that's a deployment concern, configure once
   at the server/database level. Don't "fix" this as a missing feature.

## Where to look for more detail

- `README.md` (repo root) — full feature tour: optimistic locking details,
  polymorphic many-to-many (`MorphToMany`/`MorphedByMany`), type mapping,
  repository pattern, caching, connection pooling, index attributes.
- `docs-site/` — the published Astro Starlight docs
  (https://articulate-orm.github.io/core/) — same content, browsable.
- `CHANGELOG.md` — what changed between releases; check before assuming an
  API shape from an old blog post or training data.
- `CLAUDE.md` — contributor/dev guide for the `core` repo itself (module
  map, QA commands, architectural boundaries). Not needed just to consume
  the library.

## Common mistakes to avoid when generating code against Articulate

- Don't model one shared entity class across unrelated bounded contexts —
  that's the exact anti-pattern this ORM exists to avoid.
- Don't assume a global/static `EntityManager` — always construct/inject
  one explicitly per context.
- Don't hand-write `version = version + 1` or set a `#[Version]` property
  — let `flush()` manage it.
- Don't assume annotations or XML/YAML mapping work — Articulate is
  attributes-only.
- When unsure whether a feature/attribute exists, check `README.md` or the
  `src/Attributes/` namespace rather than inventing one from a different
  ORM's API.
