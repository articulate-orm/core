# Testing

## Strategy

- Parallel tests across MySQL and PostgreSQL
- Real database connections, no mocks
- Mutation testing via Infection
- `composer qa` — CS check → Architecture check → Tests → Mutation testing

## Multi-Database Testing Setup

This document explains how to use the multi-database testing setup that allows you to run the same tests across MySQL and PostgreSQL databases automatically.

## Overview

The multi-database testing setup consists of:

1. **`DatabaseTestTrait`** - Provides data providers and utilities for multi-database testing
2. **`DatabaseTestCase`** - Base class that combines `AbstractTestCase` with `DatabaseTestTrait`
3. **Updated `AbstractTestCase`** - Enhanced with better database connection management
4. **Updated PHPUnit configuration** - Supports database-specific test suites and groups

## Quick Start

### 1. Extend DatabaseTestCase

For tests that need to run against multiple databases, extend `DatabaseTestCase`:

```php
<?php

namespace Your\Project\Tests\MyModule;

use Articulate\Connection;
use Articulate\Tests\DatabaseTestCase;

class MyDatabaseTest extends DatabaseTestCase
{
    // Your tests here
}
```

### 2. Use Data Providers

Add data provider annotations to your test methods:

```php
/**
 * Test that runs on all available databases
 * @dataProvider databaseProvider
 * @group database
 */
public function testMyFeature(Connection $connection, string $databaseName): void
{
    $this->setCurrentDatabase($connection, $databaseName);

    // Your test logic here
    $tableName = $this->getTableName('my_table', $databaseName);

    // Create table, insert data, assert results, etc.
}
```

### 3. Run Tests

Run all database tests:
```bash
vendor/bin/phpunit --testsuite="Database Tests"
```

Run tests for specific databases:
```bash
vendor/bin/phpunit --testsuite="MySQL Tests"
vendor/bin/phpunit --testsuite="PostgreSQL Tests"
```

Run tests by group:
```bash
vendor/bin/phpunit --group=database
vendor/bin/phpunit --group=mysql
vendor/bin/phpunit --group=pgsql
```

## Data Providers

### `databaseProvider()`
Returns all available database connections as `[Connection, database_name]` pairs.

### `mysqlProvider()`, `pgsqlProvider()`
Return connections for specific databases only.

### Example Usage

```php
/**
 * Test all databases
 * @dataProvider databaseProvider
 * @group database
 */
public function testAllDatabases(Connection $connection, string $databaseName): void
{
    $this->setCurrentDatabase($connection, $databaseName);
    // Test logic
}

/**
 * Test MySQL only
 * @dataProvider mysqlProvider
 * @group mysql
 */
public function testMySqlOnly(Connection $connection, string $databaseName): void
{
    $this->setCurrentDatabase($connection, $databaseName);
    // MySQL-specific test logic
}
```

## Database-Specific SQL

When writing tests that need database-specific SQL, use conditional logic:

```php
public function testTableCreation(Connection $connection, string $databaseName): void
{
    $this->setCurrentDatabase($connection, $databaseName);

    $tableName = $this->getTableName('test_table', $databaseName);

    $sql = match ($databaseName) {
        'mysql' => "CREATE TABLE `{$tableName}` (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))",
        'pgsql' => "CREATE TABLE \"{$tableName}\" (id SERIAL PRIMARY KEY, name VARCHAR(255))",
    };

    $connection->executeQuery($sql);

    // Verify table exists
    $verifySql = match ($databaseName) {
        'mysql' => "SHOW TABLES LIKE '{$tableName}'",
        'pgsql' => "SELECT tablename FROM pg_tables WHERE tablename = '{$tableName}'",
    };

    $result = $connection->executeQuery($verifySql);
    $this->assertGreaterThan(0, $result->rowCount());
}
```

## Utility Methods

### `setCurrentDatabase(Connection $connection, string $databaseName)`
Sets the current database connection and name for the test.

### `getCurrentConnection(): Connection`
Returns the current database connection.

### `getCurrentDatabaseName(): string`
Returns the current database name.

### `getTableName(string $baseName, string $databaseName): string`
Generates unique table names to avoid conflicts between databases.

### `skipIfDatabaseNotAvailable(string $databaseName)`
Skips the test if the specified database is not available.

### `isDatabaseAvailable(string $databaseName): bool`
Checks if a specific database is available.

## Environment Setup

The tests expect the following environment variables:

- `DATABASE_HOST` (default: mysql)
- `DATABASE_USER` (default: root for MySQL, postgres for PostgreSQL)
- `DATABASE_PASSWORD` (default: rootpassword)
- `DATABASE_NAME` (default: articulate_test)

For local development, create a `.env` file in the project root:

```env
DATABASE_HOST=127.0.0.1
DATABASE_USER=root
DATABASE_PASSWORD=password
DATABASE_NAME=articulate_test
```

## Docker Environment

The project includes Docker Compose setup with all three databases:

```bash
# Start all databases
docker-compose up -d mysql pgsql

# Run tests
vendor/bin/phpunit --testsuite="Database Tests"
```

## Best Practices

1. **Use `setCurrentDatabase()`** in every data provider test method
2. **Use `getTableName()`** for unique table names across databases
3. **Handle database-specific SQL** using match expressions or conditionals
4. **Test database-specific features** in separate methods when needed
5. **Use appropriate groups** for filtering tests
6. **Keep tests isolated** - each test should clean up after itself

## Examples

See `ExampleMultiDatabaseTest.php` for comprehensive examples of different testing patterns.

## Troubleshooting

- **Connection failures**: Check environment variables and database availability
- **Table conflicts**: Use `getTableName()` to generate unique table names
- **SQL syntax errors**: Ensure database-specific SQL is correct
- **Test isolation**: Each test runs in a transaction that's rolled back automatically

## Performance

- `composer test:performance` runs `tests/Performance` (MySQL only, excluded from `composer test`). It asserts exact SQL
  statement counts (one `findAll` query, one UPDATE per dirty entity, sibling slices merged into one UPDATE, no queries
  on an empty flush) and memory ceilings (bytes per hydrated entity, bytes retained after `clear()`). The ceilings carry
  ~2.5x headroom over measured values; if one trips, find the regression before raising it.
- `composer bench` runs the PHPBench suite in `benchmarks/` (time and peak memory). `composer bench:baseline` stores a
  `main` reference and `composer bench:compare` compares the working tree against it.
- The `Benchmarks` workflow runs the PR's benchmarks against both the base and the PR and prints the comparison in the job
  summary. It is informational (`continue-on-error`): shared runners are too noisy for timing gates.

