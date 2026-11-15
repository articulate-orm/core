<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

#[Entity(tableName: 'dt_immutable_items')]
class DateTimeImmutableItem {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public DateTimeImmutable $createdAt;

    #[Property]
    public ?DateTimeImmutable $deletedAt = null;
}

class DateTimeImmutableHydrationTest extends DatabaseTestCase {
    protected function tearDownTestTables(Connection $connection, string $databaseName): void
    {
        $this->setCurrentDatabase($connection, $databaseName);
        $this->cleanUpTables(['dt_immutable_items']);
    }

    #[DataProvider('databaseProvider')]
    public function testFindHydratesDateTimeImmutableProperties(string $databaseName): void
    {
        $connection = $this->createTable($databaseName);
        $connection->executeQuery(
            "INSERT INTO dt_immutable_items (id, created_at, deleted_at) VALUES (1, '2026-01-02 03:04:05', '2026-02-03 04:05:06')"
        );

        $item = (new EntityManager($connection))->find(DateTimeImmutableItem::class, 1);

        $this->assertInstanceOf(DateTimeImmutable::class, $item->createdAt);
        $this->assertSame('2026-01-02 03:04:05', $item->createdAt->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(DateTimeImmutable::class, $item->deletedAt);
        $this->assertSame('2026-02-03 04:05:06', $item->deletedAt->format('Y-m-d H:i:s'));
    }

    #[DataProvider('databaseProvider')]
    public function testRefreshHydratesDateTimeImmutableProperties(string $databaseName): void
    {
        $connection = $this->createTable($databaseName);
        $connection->executeQuery(
            "INSERT INTO dt_immutable_items (id, created_at, deleted_at) VALUES (1, '2026-01-02 03:04:05', NULL)"
        );
        $entityManager = new EntityManager($connection);
        $item = $entityManager->find(DateTimeImmutableItem::class, 1);

        $connection->executeQuery("UPDATE dt_immutable_items SET deleted_at = '2026-02-03 04:05:06' WHERE id = 1");
        $entityManager->refresh($item);

        $this->assertInstanceOf(DateTimeImmutable::class, $item->deletedAt);
        $this->assertSame('2026-02-03 04:05:06', $item->deletedAt->format('Y-m-d H:i:s'));
    }

    private function createTable(string $databaseName): Connection
    {
        $connection = $this->getConnection($databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $connection->executeQuery('DROP TABLE IF EXISTS dt_immutable_items');
        $connection->executeQuery(match ($databaseName) {
            'mysql' => 'CREATE TABLE dt_immutable_items (id INT PRIMARY KEY, created_at DATETIME NOT NULL, deleted_at DATETIME NULL)',
            'pgsql' => 'CREATE TABLE dt_immutable_items (id INT PRIMARY KEY, created_at TIMESTAMP NOT NULL, deleted_at TIMESTAMP NULL)',
            default => throw new \InvalidArgumentException("Unsupported database: {$databaseName}"),
        });

        return $connection;
    }
}
