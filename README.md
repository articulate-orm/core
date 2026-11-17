# Articulate

Context-bounded ORM for modular PHP applications that share database tables across modules.

> **Using an AI coding assistant?** Point it at [`AI.md`](./AI.md) for a
> compact, agent-oriented summary of how to use this library correctly.

## Why Articulate?

Most ORMs make the table/entity boundary the modeling boundary: one table, one primary entity class. In modular systems, that turns shared tables into shared domain objects.

A `users` table may be touched by authentication, administration, billing, public APIs, reporting, and background workers. Those contexts do not need the same fields, relations, invariants, or lifecycle behavior. A single shared `User` entity gradually becomes a coupling point between modules.

**Table width is a storage decision. Domain boundaries aren't. Don't let one dictate the other.** How many columns share a table is about storage and locality; how those columns split into contexts is domain modeling. One class per table forces the two to be the same call. Articulate lets them diverge.

Articulate makes the bounded context the modeling boundary. Several small entity classes can map to the same physical table: `LoginUser` for authentication, `AdminUser` for administration, `BillingCustomer` for billing, and read-only projection entities for public APIs.

<p align="center">
  <img src="logo.svg" alt="Articulate Logo" width="80" height="80">
</p>

Articulate still provides the expected ORM foundations: attributes, repositories, relations, migrations, type mapping, identity map, unit of work, lazy loading, and caching. The difference is that these pieces are designed around context-bounded entities from the start.

## Badges

[![CI](https://github.com/articulate-orm/core/workflows/QA/badge.svg)](https://github.com/articulate-orm/core/actions)
[![Mutation testing](https://img.shields.io/badge/Mutation%20Score-94.07%25-brightgreen)](https://github.com/articulate-orm/core/actions)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://php.net/)

## What Makes It Different?

- Multiple entity classes can map to one physical table.
- Partial entities can be marked read-only when they intentionally omit required columns.
- Each `EntityManager` owns its identity map and units of work.
- Shared-table sibling entities are handled deliberately during writes and cache eviction.
- Schema metadata, migrations, relations, lazy loading, repositories, and type conversion all understand context-bounded entities.
- MySQL and PostgreSQL are first-class targets.

## Quick Start

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

## Before / After

**Before** — one shared entity shape for every context:

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

**After** — separate entities per context, same table:

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

## When It Fits

Articulate is a good fit when different bounded contexts need different views of the same data, when adding a relation for one workflow should not affect every other workflow, or when long-running processes need tighter control over tracked entities.

If your application has one stable entity model per table and your current ORM handles that well, Articulate would still fit, just probably will not solve a meaningful problem for you.

## Core Concepts

### Context-Bounded Entities

Multiple entity classes can point to the same database table, each exposing only the fields and relationships needed for that context. Articulate merges compatible column definitions and validates for conflicts.

### Read-Only Entities

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

`ReadOnlyEntityException` is thrown at `persist()` and `remove()` — before any SQL is built.

### Optimistic Locking

A naive optimistic lock (version tied to one entity class) breaks under context-bounded entities: if only one sibling class bumps/checks the version column, another sibling can silently overwrite changes undetected. Articulate's optimistic locking is fully explicit, per-class, from that class's own attributes only:

- `#[Version]` — property-level. This class's canonical version column: hydrated as a normal `int` property, bumped (`version = version + 1`) **and** checked (`WHERE version = ?`, against the tracked value) on every `UPDATE` through this class. It implies `#[Property]`, so a bare `#[Version]` property is persisted without also writing `#[Property]`; an optional `#[Version(name: 'lock_version')]` overrides the column name with the same semantics as `#[Property(name:)]`.
- `#[VersionAware(['column', ...])]` — class-level. An inert acknowledgement marker: no SET, no WHERE, no bump at runtime. It only names the version columns (typically a sibling's `#[Version]` column) that this class knowingly writes through without taking on lost-update detection it can't reason about (e.g. a lightweight title-only edit path on a billing entity), so `articulate:validate` treats the class as accounted for rather than a gap.
- No attribute at all on a class mapping a versioned table means that class touches no version columns — a real gap, and `articulate:validate` errors on it rather than silently tolerating it.

```php
#[Entity(tableName: 'invoices')]
class Invoice
{
    #[PrimaryKey]
    public int $id;

    #[Property]
    public int $total;

    #[Property]
    public string $title;

    // #[Version] implies #[Property]; it guards this slice's own columns (total, title).
    #[Version]
    public int $version = 0;
}

#[Entity(tableName: 'invoices')]
#[VersionAware(['version'])]
class InvoiceTitleEdit
{
    #[PrimaryKey]
    public int $id;

    // Writes `title`, which is inside Invoice's guard set, but takes no lost-update
    // detection of its own — #[VersionAware(['version'])] declares that crossing so
    // articulate:validate treats it as acknowledged rather than a gap.
    #[Property]
    public string $title;
}

$invoice = $em->find(Invoice::class, 1);
$invoice->version; // 3

// Someone else updates and commits first — the row's version is now 4.

$invoice->total = 100;
$em->persist($invoice);
$em->flush(); // throws OptimisticLockException: WHERE version = 3 matched 0 rows
```

`#[VersionAware]` is an inert acknowledgement marker — no SET, no WHERE, no bump; it only names the version columns a slice acknowledges, for `articulate:validate`. A column in both a class's own `#[Version]` property and its own `#[VersionAware]` list is merely redundant. `#[Version]` properties must be typed `int`; a migration-generated column for one gets `DEFAULT 0` automatically. `OptimisticLockException` doesn't distinguish a stale version from a deleted row — both are "zero rows matched." Do not assign to a `#[Version]` property yourself: the column is ORM-managed (bumped server-side as `version = version + 1`, and checked in `WHERE` against the value the ORM is tracking). A manual assignment is rejected at flush time with a `ManagedVersionColumnException` rather than silently dropped — let the ORM own it.

**Recovering from a conflict.** A flush that throws rolls its transaction back and leaves the entities' in-memory `#[Version]` properties at their pre-flush values — the `+1` bump is committed just before post-update callbacks (so a `#[PostUpdate]` handler sees the value the row now carries) and reverted if the flush never commits. So the failed flush does not poison a retry: re-`find()` the entity (or resolve the conflict another way) and flush again. There is no "EM is now closed" state to reset. Do not, however, write the same row through two different `#[Version]`-checking classes in a single flush — the first `UPDATE` bumps the shared column and the second then conflicts with itself.

**Run `articulate:validate` in CI.** The guard-set guarantee holds only if it is enforced: there is no runtime check, so a slice writing a column inside a sibling's guard set silently drops out of that guard's lost-update detection until `validate` catches it. It errors on **rival counters** — two distinct `#[Version]` columns on a table whose guard sets overlap (never downgraded) — and on a slice persisting a column inside another slice's guard set without its own `#[Version]` or a `#[VersionAware]` acknowledgement of that version column. It also errors on a slice mapping another slice's `#[Version]` column as a plain `#[Property]` (never downgraded, not cleared by `#[VersionAware]`): `ManagedVersionColumnException` only guards the class that owns the column through `#[Version]`, so at runtime such a sibling can overwrite the counter with any value, and only `validate` catches it. The `--lenient` flag downgrades the missing-acknowledgement error to a warning.

### Memory-Efficient Unit of Work

- Clear entities from memory that are no longer needed within specific operations
- Different units of work can track their own entities independently
- Entity manager combines all unit-of-work changes into minimal database queries during flush

Useful for processing large datasets, complex business operations spanning multiple contexts, and long-running processes with varying entity lifecycles.

### Polymorphic Many-To-Many Relations

Use `MorphToMany` / `MorphedByMany` when several entity types share one pivot table, such as tagging orders and customers through `taggables`.

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

Registered morph aliases are used for owning and inverse relation loading. If no alias is registered, Articulate falls back to storing and loading the full entity class name.

When Articulate generates a polymorphic pivot schema, it uses the composite key (`taggable_type`, `taggable_id`, `tag_id`) as the relation identity. A separate technical `id` column is not required for collection loading or persistence.

## Type Mapping System

Built-in mappings: `bool` ↔ `TINYINT(1)`, `int` ↔ `INT`, `float` ↔ `FLOAT`, `string` ↔ `VARCHAR(255)`, `DateTimeInterface` ↔ `DATETIME`.

Custom class mappings and `TypeConverterInterface` for complex types. Priority-based resolution when a class implements multiple interfaces with registered mappings.

## Repository Pattern

```php
$userRepo = $em->getRepository(User::class);
$user = $userRepo->find(1);
$users = $userRepo->findBy(['status' => 'active']);
$user = $userRepo->findOneBy(['email' => 'user@example.com']);
```

Custom repositories via `#[Entity(repositoryClass: UserRepository::class)]` extending `AbstractRepository`.

## Caching

Articulate has three independent cache layers, all using PSR-6 (`CacheItemPoolInterface`). Pass the same pool instance to share backend, or separate instances for isolation.

### Second-Level Cache

Cross-request entity cache. Survives beyond a single `EntityManager` instance.

```
Request A: identity map miss → DB hit → entity stored in L2 cache
Request B: identity map miss → L2 cache hit → DB skipped entirely
Request C: identity map miss → L2 cache hit → DB skipped entirely
```

Pass a PSR-6 pool as `secondLevelCache:` to enable it. Without it, `find()` is never cached — `resultCache:` alone only backs `enableResultCache()`. Both options may point at the same pool.

```php
$em = new EntityManager(
    $connection,
    secondLevelCache: $entityPool,
    secondLevelCacheTtl: 3600,
);
```

`find()` checks the identity map first, then the L2 cache, then the database. On `flush()`, modified and deleted entity entries are evicted automatically — stale data is never served after a write.

### Collection Cache

Opt a `QueryBuilder` query into caching its matched **id-list** (never row data) in the `secondLevelCache:` pool. A repeat call resolves entities from the identity map or the L2 entity cache.

```php
$active = $em->createQueryBuilder(User::class)
    ->where('status = ?', 'active')
    ->cacheable(ttl: 300)
    ->getResult();
```

Invalidation is coarse: every table has a **table generation** counter, bumped once per `flush()` that inserts, updates, deletes or soft-deletes rows in it. A cached list is keyed by its root table's generation, so any such write makes it stop matching, including updates that move a row into or out of the predicate. Joined tables (`join`, `leftJoin`, `rightJoin`, `crossJoin`, aliased or not) count too: their generations are part of the key, so a write to a joined table also invalidates. Mapping (pivot) tables are written tables too: pivot rows `flush()` deletes along with a removed entity, or writes while syncing a many-to-many collection, bump the mapping table's generation, so a list joining it is invalidated. The cost is hit rate: a query joined against a frequently written table misses after each of those writes. Writes to tables the query does not read keep it a hit. All generations are read in one batched pool call; if any is unreadable, the query runs uncached. Tables read through a `QueryBuilder` subquery passed to `whereExists`, `orWhereExists`, `whereIn` or `whereNotIn` count too, recursively: the subquery's own table, its joins and its nested subqueries. Not covered: subqueries written as raw SQL strings, and writes made through `QueryBuilder` DML or raw SQL.

Tables a query reaches only through raw SQL (`whereRaw`, a raw join condition) are not detected, so writes to them do not invalidate the list. Declare them with `dependsOn:`; they are merged with the detected tables:

```php
$authors = $em->createQueryBuilder(Author::class)
    ->whereRaw('EXISTS (SELECT 1 FROM posts WHERE posts.author_id = authors.id)')
    ->cacheable(ttl: 300, dependsOn: ['posts'])
    ->getResult();
```

The bump happens after `flush()` commits its own transaction, so concurrent readers never re-cache pre-commit rows under the new generation, and a flush that fails bumps nothing. If you call `flush()` inside your own transaction, the bump happens when `flush()` returns, before your COMMIT, so a reader in that window can still cache rows that are not committed yet.

### Query Result Cache

Cache raw result sets from `QueryBuilder` queries. Useful for read-heavy queries that don't change often.

```php
$users = $em->createQueryBuilder(User::class)
    ->from('users')
    ->where('status', 'active')
    ->enableResultCache(lifetime: 300, resultCacheId: 'active_users')
    ->getResult();
```

- Custom cache key via `resultCacheId`, or auto-generated from query shape + parameters
- Locked queries (`FOR UPDATE`) are never cached
- Call `disableResultCache()` to opt out per query

### Statement Cache

Caches compiled SQL strings (query structure, not results). Eliminates repeated SQL compilation for queries with the same shape but different parameter values.

```php
$em = new EntityManager($connection, statementCache: $cachePool);
```

Transparent — no per-query opt-in needed. Failures are silently ignored so a broken cache backend never breaks queries.

## Connection Pooling

Enable PDO persistent connections to reuse open database connections across requests:

```php
$connection = new Connection(
    dsn: 'mysql:host=127.0.0.1;dbname=myapp',
    user: 'root',
    password: 'secret',
    persistent: true,
);
```

Skips TCP handshake and authentication overhead on each request. Pair with a pool-aware cache backend for full cross-request performance.

## MySQL Table Options (ENGINE, CHARSET, COLLATE)

Articulate does not append table options like `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=...` to generated `CREATE TABLE` statements. This is intentional.

Storage engine and character set are deployment concerns, not schema concerns. The right values depend on the MySQL version, the hosting environment, and the application's locale requirements — there is no single correct default. Hardcoding them would mean either inheriting outdated assumptions or overriding a deliberate server configuration.

Instead, Articulate delegates to the server's configured defaults:

- **ENGINE** — InnoDB is the MySQL default since 5.7 and is the only engine that supports foreign keys; Articulate's FK generation already implies it.
- **CHARSET / COLLATE** — configure once at the server or database level (`CREATE DATABASE ... CHARACTER SET utf8mb4`). All tables created in that database inherit the correct charset without per-table repetition.

If per-table overrides are ever needed, the right path is an explicit option on `#[Entity]`, not a framework-wide hardcoded string.

## Index Attribute Design

`#[Index]` takes `fields` — PHP property names, not column names:

```php
#[Index(fields: ['userId', 'createdAt'])]
#[Entity]
class Order { ... }
```

This keeps index definitions coupled to the entity model. When a property is renamed alongside its column, PHP tooling catches the broken reference in `fields`. Raw column strings would silently diverge.

**Expression and prefix indexes** (e.g. `LOWER(email)`, `title(100)`) have no PHP property to reference. If that need arises, a dedicated `ExpressionIndex` attribute will be introduced as an explicit escape hatch rather than mixing column-string support into `Index`.

## License

Licensed under the Apache License 2.0. See [LICENSE](./LICENSE).
