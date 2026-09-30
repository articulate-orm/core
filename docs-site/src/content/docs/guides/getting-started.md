---
title: Getting Started
description: Install Articulate, configure database access, and wire the core services.
sidebar:
  order: 1
---

import { Steps, Aside } from '@astrojs/starlight/components';

Install Articulate, configure database access, and wire the two services used by the rest of the examples.

## Installation

```bash frame="terminal"
composer require articulate-orm/core
```

## Configuration

Configure database access through environment variables:

```ini title=".env"
DATABASE_DSN=mysql:host=mysql;dbname=articulate_test;charset=utf8mb4
DATABASE_USER=user
DATABASE_PASSWORD=userpassword
```

<Aside type="tip">
	If you're switching between MySQL and PostgreSQL migration folders (as the demo project does), also set `ARTICULATE_MIGRATIONS_PATH` so migration commands know which directory to read/write.
</Aside>

## Services

Register the core Articulate services in your container:

- `Articulate\Connection` receives the DSN, username, and password.
- `Articulate\Modules\EntityManager\EntityManager` receives the `Connection`.

Most examples use `EntityManager` directly to persist, find, query, remove, and flush entities.

## First run

<Steps>
1. Start your database services (Docker Compose or otherwise).
2. Initialize the migration tracking table.
3. Apply pending migrations.
4. Run your first command against real data.
</Steps>

```bash frame="terminal"
docker compose up -d
docker compose exec php bin/console articulate:init
docker compose exec php bin/console articulate:migrate
docker compose exec php bin/console app:catalog:crud
```

## Next steps

Continue to [Entity Mapping](/guides/entity-mapping/) to map your first PHP classes to database tables, or read [Context-Bounded Entities](/concepts/context-bounded-entities/) to understand what makes Articulate different.
