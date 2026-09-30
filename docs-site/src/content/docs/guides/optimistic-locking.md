---
title: Optimistic Locking
description: Detect lost updates with version columns across bounded-context classes — fully per-slice, not row-wide.
sidebar:
  order: 10
---

import { Aside, Steps } from '@astrojs/starlight/components';

Detect lost updates when two contexts write the same row, without holding a database lock between the read and the write.

Optimistic locking is the clearest expression of Articulate's differentiator. A naive version-per-entity-class lock **breaks** under context-bounded entities: if only one sibling class mapping a table bumps and checks the version column, another sibling can silently overwrite changes undetected. Articulate makes the contract explicit and per-class — every class mapping a versioned table must account for the version column, either by checking it (`#[Version]`) or by bumping it without checking (`#[VersionAware]`).

<Aside type="note">
	Contrast with [pessimistic locking](/guides/transactions-locking/): `SELECT ... FOR UPDATE` blocks other writers for the duration of a transaction, while optimistic locking never locks and instead detects the conflict at write time.
</Aside>

## Two bounded-context classes, one table

A Billing feature maps one physical `invoices` table through two classes:

```php
#[Entity(tableName: 'invoices')]
final class Invoice
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    #[Property(name: 'title', maxLength: 160)]
    public string $title;

    // The canonical version column: bumped AND checked on every UPDATE.
    #[Property]
    #[Version]
    public int $version = 0;
}

#[Entity(tableName: 'invoices')]
#[VersionAware(['version'])]
final class InvoiceTitleEdit
{
    #[PrimaryKey]
    #[AutoIncrement]
    public ?int $id = null;

    // Narrow edit path: bumps the shared version column but never checks it.
    #[Property(name: 'title', maxLength: 160)]
    public string $title;
}
```

- `Invoice` carries `#[Version]`. Every `UPDATE` through this class runs `version = version + 1` **and** guards the write with `WHERE version = ?`.
- `InvoiceTitleEdit` declares class-level `#[VersionAware(['version'])]`. It bumps the shared `version` column on `UPDATE` so a full-model writer's check still fires, but a lightweight title edit doesn't take on lost-update detection it can't reason about — it never checks the version itself.

## Happy path

A fresh insert starts at `version = 0`; the next `UPDATE` guards on the version it read and bumps the row to `1`:

```php
$invoice = new Invoice();
$invoice->number = 'INV-1001';
$invoice->title = 'Initial invoice';
$em->persist($invoice);
$em->flush();               // version = 0 after INSERT

$invoice->amount = 150.0;
$em->persist($invoice);
$em->flush();               // UPDATE ... WHERE version = 0 → row is now version = 1
```

The in-memory `#[Version]` property is bumped to match the committed row.

## Conflict

When a concurrent writer moves the row first, the stale flush guards on a version the row no longer holds — the `UPDATE` matches zero rows and Articulate throws:

```php
$invoice = $em->find(Invoice::class, $id);          // reads version = 1

// A separate EntityManager loads, mutates, and commits first → row goes to version = 2.

$invoice->amount = 175.0;
$em->persist($invoice);

try {
    $em->flush();                                   // UPDATE ... WHERE version = 1 matches 0 rows
} catch (OptimisticLockException $e) {
    // stale-version conflict detected
}
```

<Aside type="caution">
	`OptimisticLockException` does not distinguish a stale version from a deleted row — both are "zero rows matched." Telling them apart would need an extra `SELECT` the exception is meant to avoid.
</Aside>

## Recovery

A failed flush does not poison in-memory state: the in-memory `#[Version]` is not bumped past its pre-flush value, and there's no "manager is closed" state to reset.

<Steps>
1. `$em->clear()` — drop stale identity-map entries.
2. Re-`find()` the current row.
3. Re-apply your change.
4. `flush()` again against the up-to-date version.
</Steps>

```php
$em->clear();
$fresh = $em->find(Invoice::class, $id);            // reads the current version
$fresh->amount = 200.0;
$em->persist($fresh);
$em->flush();                                       // succeeds against the up-to-date version
```

## Bump-only sibling

Editing through the `#[VersionAware]` sibling bumps the shared column without checking it, keeping a checking sibling's lost-update detection honest:

```php
$titleEdit = $em->find(InvoiceTitleEdit::class, $id);
$titleEdit->title = 'Retitled by the narrow edit path';
$em->persist($titleEdit);
$em->flush();                                       // shared version bumps; no WHERE version = ? guard
```

The next writer through `Invoice` will now see its own read as stale unless it reloaded after this edit — exactly the safety the bump provides.

## Validate coverage (CI-critical)

There is **no runtime enforcement** that every class mapping a versioned table accounts for the version column. A class that maps `invoices` with neither `#[Version]` nor `#[VersionAware]` silently drops out of lost-update detection until `articulate:validate` catches it:

```
Class "App\Features\Billing\Entity\InvoiceUntracked" does not account for version column "version" on table "invoices".
```

<Aside type="danger" title="Run articulate:validate in CI">
	So a new bounded-context class can't quietly opt out of the version contract. When a table has more than one distinct `#[Version]` column across its classes, `validate` reports it at info level.
</Aside>

## Common pitfalls

- A `#[Version]` property must be typed `int`.
- Do **not** write the same row through two different `#[Version]`-checking classes in one flush — the first `UPDATE` bumps the shared column and the second conflicts with itself. Use a `#[VersionAware]` sibling for the secondary write path instead.
- `OptimisticLockException` cannot tell a stale version from a deleted row; treat both as "the row moved on."
- Adding a class that maps a versioned table without declaring `#[Version]` or `#[VersionAware]` compiles and runs — only `articulate:validate` surfaces the gap.

## Background

This per-slice model replaced an earlier row-wide version model in `2.0.0` — see [ADR 0001](/reference/adr-0001-per-slice-version-guards/) for the full rationale and rejected alternatives.
