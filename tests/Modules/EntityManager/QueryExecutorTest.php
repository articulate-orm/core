<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\MorphTo;
use Articulate\Attributes\Relations\OneToMany;
use Articulate\Attributes\Relations\OneToOne;
use Articulate\Connection;
use Articulate\Modules\EntityManager\QueryExecutor;
use Articulate\Modules\Generators\GeneratorInterface;
use Articulate\Modules\Generators\GeneratorRegistry;
use PHPUnit\Framework\TestCase;

#[Entity]
class QueryExecutorTestEntity {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[Property(nullable: true)]
    public ?string $description = null;
}

#[Entity]
class QueryExecutorTestEntityWithoutId {
    #[Property]
    public string $name;
}

#[Entity]
class QueryExecutorUuidEntity {
    #[PrimaryKey(generator: 'uuid_v4')]
    public ?string $id = null;

    #[Property]
    public string $name;
}

#[Entity]
class QueryExecutorImplicitIdEntity {
    public ?int $id = null;

    #[Property]
    public string $name;
}

#[Entity]
class QueryExecutorPrefixedIdEntity {
    #[PrimaryKey(generator: 'prefixed', options: ['prefix' => 'ord_'])]
    public ?string $id = null;

    #[Property]
    public string $name;
}

/**
 * Non-primary-key property declared BEFORE the generated primary key.
 * Kills the mutation that turns `instanceof ReflectionProperty && isPrimaryKey()`
 * into `instanceof ReflectionProperty || isPrimaryKey()` in executeInsert()'s
 * generated-id-column-assignment loop (any ReflectionProperty would short-circuit true).
 */
#[Entity]
class QueryExecutorFieldOrderEntity {
    #[Property]
    public string $label;

    #[PrimaryKey(generator: 'uuid_v4')]
    public ?string $id = null;
}

#[Entity]
class QueryExecutorMorphTarget {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity]
class QueryExecutorMorphOwner {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[MorphTo]
    public ?QueryExecutorMorphTarget $commentable = null;
}

#[Entity]
class QueryExecutorRelationTarget {
    #[PrimaryKey]
    public int $id;

    #[OneToMany(targetEntity: QueryExecutorRelationOwner::class, ownedBy: 'target')]
    public array $owners = [];
}

#[Entity]
class QueryExecutorRelationOwner {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[ManyToOne(targetEntity: QueryExecutorRelationTarget::class)]
    public ?QueryExecutorRelationTarget $target = null;

    #[OneToMany(targetEntity: QueryExecutorRelationTarget::class, ownedBy: 'owners')]
    public array $children = [];

    #[OneToOne(targetEntity: QueryExecutorRelationTarget::class, ownedBy: 'owners')]
    public ?QueryExecutorRelationTarget $inverse = null;
}

class QueryExecutorTest extends TestCase {
    private QueryExecutor $queryExecutor;

    private Connection $connection;

    private GeneratorRegistry $generatorRegistry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->generatorRegistry = $this->createStub(GeneratorRegistry::class);
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);
    }

    public function testExecuteInsert(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Test Entity';
        $entity->description = 'Test Description';

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('INSERT INTO'),
                $this->equalTo([1, 'Test Entity', 'Test Description'])
            );

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertEquals(1, $result);
    }

    public function testExecuteInsertGeneratesAndAssignsUuidId(): void
    {
        $generatedId = '4f123ec8-f1b7-4956-9f08-6dfb89d9014f';
        $generator = $this->createStub(GeneratorInterface::class);
        $generator->method('generate')->willReturn($generatedId);

        $this->generatorRegistry = $this->createMock(GeneratorRegistry::class);
        $this->generatorRegistry->expects($this->once())
            ->method('getGenerator')
            ->with('uuid_v4')
            ->willReturn($generator);
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorUuidEntity();
        $entity->name = 'Generated Entity';

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('INSERT INTO'),
                $this->equalTo(['Generated Entity', $generatedId])
            );

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertSame($generatedId, $result);
        $this->assertSame($generatedId, $entity->id);
    }

    public function testExecuteInsertWithNullValues(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 2;
        $entity->name = 'Test Entity';
        $entity->description = null; // Should be included since it's nullable

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('INSERT INTO'),
                $this->equalTo([2, 'Test Entity', null])
            );

        $this->queryExecutor->executeInsert($entity);
    }

    public function testExecuteUpdate(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Updated Name';
        $entity->description = 'Updated Description';

        $changes = [
            'name' => 'Updated Name',
            'description' => 'Updated Description',
        ];

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('UPDATE'),
                $this->equalTo(['Updated Name', 'Updated Description', 1])
            );

        $this->queryExecutor->executeUpdate($entity, $changes);
    }

    public function testExecuteUpdateWithEmptyChanges(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        // No database call should be made for empty changes
        $this->connection->expects($this->never())
            ->method('executeQuery');

        $this->queryExecutor->executeUpdate($entity, []);
    }

    public function testExecuteDelete(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Test Entity';

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('DELETE FROM'),
                $this->equalTo([1])
            );

        $this->queryExecutor->executeDelete($entity);
    }

    public function testExecuteSelect(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'Test'],
            ['id' => 2, 'name' => 'Test2'],
        ];

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn($expectedResults);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM test_table', ['param1'])
            ->willReturn($statement);

        $result = $this->queryExecutor->executeSelect('SELECT * FROM test_table', ['param1']);

        $this->assertEquals($expectedResults, $result);
    }

    public function testExecuteInsertPropagatesExceptions(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Test Entity';

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \PDOException('DB constraint violation'));

        $this->expectException(\PDOException::class);
        $this->queryExecutor->executeInsert($entity);
    }

    public function testExecuteUpdatePropagatesExceptions(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Test Entity';

        $changes = ['name' => 'New Name'];

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \PDOException('DB constraint violation'));

        $this->expectException(\PDOException::class);
        $this->queryExecutor->executeUpdate($entity, $changes);
    }

    public function testExecuteDeletePropagatesExceptions(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \PDOException('DB constraint violation'));

        $this->expectException(\PDOException::class);
        $this->queryExecutor->executeDelete($entity);
    }

    public function testExecuteSelectHandlesMockExceptions(): void
    {
        // Mock a PHPUnit mock exception
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('Method executeQuery() should not have been called'));

        // Should return empty array
        $result = $this->queryExecutor->executeSelect('SELECT * FROM test');
        $this->assertEquals([], $result);
    }

    public function testExecuteInsertTreatsPlainIdPropertyAsImplicitPrimaryKey(): void
    {
        $entity = new QueryExecutorImplicitIdEntity();
        $entity->name = 'Implicit';

        $this->connection->method('lastInsertId')->willReturn('42');
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('INSERT INTO'), $this->equalTo(['Implicit']));

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertSame(42, $result);
        $this->assertSame(42, $entity->id);
    }

    public function testExecuteInsertPassesGeneratorOptionsToTheGenerator(): void
    {
        $generator = $this->createMock(GeneratorInterface::class);
        $generator->expects($this->once())
            ->method('generate')
            ->with(QueryExecutorPrefixedIdEntity::class, ['prefix' => 'ord_'])
            ->willReturn('ord_1');

        $this->generatorRegistry = $this->createStub(GeneratorRegistry::class);
        $this->generatorRegistry->method('getGenerator')->willReturn($generator);
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorPrefixedIdEntity();
        $entity->name = 'Prefixed';

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('INSERT INTO'), $this->equalTo(['Prefixed', 'ord_1']));

        $this->assertSame('ord_1', $this->queryExecutor->executeInsert($entity));
        $this->assertSame('ord_1', $entity->id);
    }

    public function testExecuteInsertWritesOwningRelationColumnsOnly(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 7;

        $entity = new QueryExecutorRelationOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->target = $target;
        $entity->children = [];
        $entity->inverse = null;

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringContainsString('target_id', $capturedSql);
        $this->assertStringNotContainsString('children', $capturedSql);
        $this->assertStringNotContainsString('inverse', $capturedSql);
        $this->assertSame([1, 'Owner', 7], $capturedValues);
    }

    public function testExecuteUpdateWritesOwningRelationColumnsOnly(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 9;

        $entity = new QueryExecutorRelationOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->target = $target;
        $entity->children = [];
        $entity->inverse = null;

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['name' => 'Owner']);

        $this->assertStringContainsString('target_id = ?', $capturedSql);
        $this->assertStringNotContainsString('children', $capturedSql);
        $this->assertStringNotContainsString('inverse', $capturedSql);
        $this->assertSame(['Owner', 9, 1], $capturedValues);
    }

    // ── Mutation killers for 242-256 ────────────────────────────────────────

    public function testExecuteInsertAssignsGeneratedIdToCorrectPrimaryKeyColumnWhenOtherPropertiesPrecedeIt(): void
    {
        $generatedId = '550e8400-e29b-41d4-a716-446655440000';
        $generator = $this->createStub(GeneratorInterface::class);
        $generator->method('generate')->willReturn($generatedId);

        $this->generatorRegistry = $this->createStub(GeneratorRegistry::class);
        $this->generatorRegistry->method('getGenerator')->willReturn($generator);
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorFieldOrderEntity();
        $entity->label = 'Label Value';

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        // 'id' column must appear exactly once — the OR-mutant would also append it
        // for the 'label' property, but because 'label' is skipped as a non-PK,
        // 'id' is the only generated-id column injected.
        $this->assertSame(1, substr_count($capturedSql, 'id'), $capturedSql);
        $this->assertStringContainsString('label', $capturedSql);
        $this->assertSame(['Label Value', $generatedId], $capturedValues);
        $this->assertSame($generatedId, $entity->id);
    }

    public function testExecuteUpdateSkipsOnlyPropertiesMatchingColumnNameNotAnyProperty(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Keep';
        $entity->description = 'Keep description';

        // 'nonexistent_column' does not map to any property — the OR mutant would
        // match the first ReflectionProperty encountered regardless of column name,
        // silently writing to the wrong column.
        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['name' => 'Updated', 'nonexistent_column' => 'ignored']);

        $this->assertStringContainsString('name = ?', $capturedSql);
        $this->assertStringNotContainsString('nonexistent_column', $capturedSql);
        $this->assertSame(['Updated', 1], $capturedValues);
    }

    public function testExecuteUpdateWithEmptyChangesReturnsNullWithoutExecutingQuery(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $this->connection->expects($this->never())->method('executeQuery');

        $result = $this->queryExecutor->executeUpdate($entity, []);

        $this->assertNull($result);
    }

    public function testDeferredVersionReconciliationReturnsNullForEmptyOriginalValues(): void
    {
        $metadata = new \Articulate\Schema\EntityMetadata(QueryExecutorTestEntity::class);
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $result = $this->queryExecutor->deferredVersionReconciliation($metadata, $entity, []);

        $this->assertNull($result);
    }

    public function testDeferredVersionReconciliationReturnsBumpForNonEmptyOriginalValues(): void
    {
        $metadata = new \Articulate\Schema\EntityMetadata(QueryExecutorTestEntity::class);
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $result = $this->queryExecutor->deferredVersionReconciliation($metadata, $entity, ['id' => 1]);

        $this->assertNotNull($result);
    }

    public function testGetVersionColumnValueUsesNullSafeCallWhenPropertyIsMissing(): void
    {
        $metadata = new \Articulate\Schema\EntityMetadata(QueryExecutorTestEntity::class);
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        // Column that maps to no known property name — the real code's
        // nullsafe `$property?->getValue($entity)` must return null rather
        // than throwing (mutant removes the nullsafe operator).
        $result = $this->queryExecutor->getVersionColumnValue($metadata, $entity, 'nonexistent_column');

        $this->assertNull($result);
    }

    public function testExecuteInsertWritesMorphToTypeAndIdColumnsWhenRelatedEntitySet(): void
    {
        $target = new QueryExecutorMorphTarget();
        $target->id = 55;
        $target->name = 'Target';

        $entity = new QueryExecutorMorphOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->commentable = $target;

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringContainsString('commentable_type', $capturedSql);
        $this->assertStringContainsString('commentable_id', $capturedSql);
        $this->assertContains(QueryExecutorMorphTarget::class, $capturedValues);
        $this->assertContains(55, $capturedValues);
    }

    public function testExecuteInsertOmitsMorphToColumnsWhenRelatedEntityIsNull(): void
    {
        $entity = new QueryExecutorMorphOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->commentable = null;

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringNotContainsString('commentable_type', $capturedSql);
        $this->assertStringNotContainsString('commentable_id', $capturedSql);
    }

    public function testExecuteUpdateWritesMorphToColumnsWhenRelatedEntitySet(): void
    {
        $target = new QueryExecutorMorphTarget();
        $target->id = 77;

        $entity = new QueryExecutorMorphOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->commentable = $target;

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['name' => 'Owner']);

        $this->assertStringContainsString('commentable_type = ?', $capturedSql);
        $this->assertStringContainsString('commentable_id = ?', $capturedSql);
        $this->assertContains(QueryExecutorMorphTarget::class, $capturedValues);
        $this->assertContains(77, $capturedValues);
    }
}
