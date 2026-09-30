---
title: Boundaries & Conventions
description: Deptrac layer boundaries, coding conventions, and the QA pipeline that enforces them.
sidebar:
  order: 2
---

import { Aside, Badge } from '@astrojs/starlight/components';

## Architectural boundaries

Deptrac enforces 12 layers. The dependency direction is one-way:

```text
Commands → Migrations → Database → QueryBuilder → Repository →
EntityManager → Schema → Attributes → Utils → Collection →
Generators → Exceptions
```

```bash frame="terminal"
composer architecture:check
```

Run this after adding any cross-module dependency — Deptrac fails the build if a lower layer starts depending on a higher one.

## Tech stack

- **Language**: PHP 8.4+ (readonly properties, enums, fibers where appropriate)
- **Databases**: MySQL 8.0, PostgreSQL 15 — both must work for every SQL-touching change
- **Mapping**: PHP attributes only — no XML, no YAML, no annotations
- **Testing**: PHPUnit with real DB connections — no mocks
- **QA**: php-cs-fixer, PHPStan level 6, Deptrac, Infection mutation testing

<Aside type="danger" title="Do NOT use">
	Doctrine annotations, global singletons, static state, process-wide registries.
</Aside>

## Coding conventions

- **Naming**: Classes = PascalCase, methods/vars = camelCase, DB columns = snake_case via naming convention resolver.
- **Types**: Full type hints everywhere — no `mixed` unless unavoidable, no `@param` when the signature suffices.
- **Comments**: none unless the *why* is non-obvious (hidden constraint, workaround, subtle invariant).
- **Interfaces before implementations**: depend on abstractions, not concrete classes.
- **Exceptions**: use types from `src/Exceptions/` — never throw `\Exception` directly.
- **File size**: keep classes focused; consider splitting past ~300 lines.
- **No static state**: zero static properties/methods that accumulate state across requests.
- **Dual-DB**: every SQL-touching feature must work on both MySQL and PostgreSQL — use `match($databaseName)` in tests.

## QA pipeline

```bash frame="terminal"
composer cs:check            # Dry-run php-cs-fixer
composer cs:fix              # Apply CS fixes
composer static:check        # PHPStan level 6
composer architecture:check  # Deptrac layer validation
composer complexity:check    # PHPMD cyclomatic/NPath analysis
composer test                # PHPUnit — MySQL + PostgreSQL
composer test:mutation       # Infection mutation testing
composer qa                  # Full pipeline: CS → architecture → tests → mutation
```

<Badge text="Tests use real DB connections — no mocks" variant="caution" />

Tests run inside the PHP container against both MySQL 8.0 and PostgreSQL 15 (`#[DataProvider('databaseProvider')]` runs each test on both):

```bash frame="terminal"
docker compose up -d && docker compose exec php bash
composer install
composer test                         # all tests
composer test -- --filter=SomeTest    # single class
```

## Read replicas

No built-in read/write routing — intentional. Let infrastructure handle it (PgBouncer, ProxySQL, RDS Proxy), or use the per-context design described in [Unit of Work & Identity Map](/concepts/unit-of-work-identity-map/#read-replicas-via-separate-contexts).
