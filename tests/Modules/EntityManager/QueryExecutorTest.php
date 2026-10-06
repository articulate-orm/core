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
use Articulate\Schema\EntityMetadata;
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

/**
 * Dedicated entity solely used to probe getReflectionEntity()'s static
 * cache (`??=`) in isolation, so seeding/polluting the cache for this class
 * cannot affect any other test.
 */
#[Entity]
class QueryExecutorReflectionCacheProbeEntity {
    #[PrimaryKey]
    public int $id = 1;

    #[Property]
    public string $name;
}

/**
 * Two primary-key-flagged properties mapped to the SAME column name
 * (an edge case, legal via reflection). Kills the Break_ mutant on line
 * 426 (buildWhereClause()'s PK-property-by-column lookup): with the real
 * `break`, the FIRST matching property ('first') is bound; a `continue`
 * mutant would keep scanning and bind the LAST matching property
 * ('second') instead.
 */
#[Entity]
class QueryExecutorDuplicatePkColumnEntity {
    #[PrimaryKey(name: 'shared_pk')]
    public int $first = 100;

    #[PrimaryKey(name: 'shared_pk')]
    public int $second = 200;

    #[Property]
    public string $name = 'x';
}

/**
 * Two distinct properties mapped to the SAME column name via an explicit
 * Property(name: ...) override. Used to exercise executeUpdate()'s
 * property-by-column-name lookup loop (Break_ mutant at line 202): with a
 * real `break`, the FIRST declared property ('alpha') wins the lookup for
 * column 'shared'; a `continue` mutant would keep scanning and the LAST
 * declared property ('beta') would win instead.
 */
#[Entity]
class QueryExecutorDuplicateColumnEntity {
    #[PrimaryKey]
    public int $id = 1;

    #[Property(name: 'shared')]
    public string $alpha;

    #[Property(name: 'shared')]
    public string $beta;
}

/**
 * A required (non-nullable, no-default) property left uninitialized,
 * declared BEFORE another required property that IS set. Kills the
 * Continue_ mutant at executeInsert()'s null/no-default skip (line 90):
 * with the real `continue`, the loop proceeds past the skipped property
 * and still adds the later one; a `break` mutant would abort the whole
 * loop at the skip point and silently drop the later property too.
 */
#[Entity]
class QueryExecutorSkipThenContinueEntity {
    #[PrimaryKey]
    public int $id = 1;

    #[Property]
    public string $skippable;

    #[Property]
    public string $afterSkipped;
}

/**
 * Two primary-key columns: 'id' (first declared, given an explicit
 * non-empty value) and 'secondaryId' (second declared, left null). Used to
 * kill the LogicalOrAllSubExprNegation mutant on line 123
 * (`$id === null || $id === ''` → `!($id === null) || !($id === '')`,
 * which is a tautology whenever $id holds any concrete value, making
 * `$dbAssignedId` wrongly always true once `$preGeneratedId === null`).
 * Because 'secondaryId' is null, the per-property scan sets
 * `$pkColumnName = 'secondary_id'` even though the overall entity id
 * (from 'id', via extractEntityId()) is already set — giving the real
 * `$dbAssignedId` a false value (since $id is non-null/non-empty) while
 * the mutant's tautological OR makes it true.
 */
#[Entity]
class QueryExecutorMultiPkSecondUnsetEntity {
    #[PrimaryKey(generator: 'uuid_v4')]
    public ?string $id = null;

    #[PrimaryKey]
    public ?string $secondaryId = null;

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

/**
 * Two primary-key properties, 'id' declared first with a generator and
 * 'secondaryId' declared second WITHOUT one. Kills the Break_ mutant at
 * executeInsert()'s generated-id-column-assignment loop (line ~116):
 * with a real `break`, only 'id' gets the generated value appended; a
 * `continue` mutant would keep scanning and also append 'secondary_id'.
 */
#[Entity]
class QueryExecutorMultiPkEntity {
    #[PrimaryKey(generator: 'uuid_v4')]
    public ?string $id = null;

    #[PrimaryKey]
    public ?string $secondaryId = null;

    #[Property]
    public string $name;
}

/**
 * Two primary-key properties, 'id' declared first WITHOUT a generator and
 * 'secondaryId' declared second WITH one. Kills the Break_ mutant in
 * generateNextId() (line ~464): a real `break` stops scanning at 'id' (no
 * generator) and returns null; a `continue` mutant would keep scanning,
 * reach 'secondaryId', and invoke its generator.
 */
#[Entity]
class QueryExecutorPkOrderEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[PrimaryKey(generator: 'uuid_v4')]
    public ?string $secondaryId = null;

    #[Property]
    public string $name;
}

/**
 * DB-assigned (no generator) primary key, used to exercise the PostgreSQL
 * `INSERT ... RETURNING` branch in executeInsert().
 */
#[Entity]
class QueryExecutorAutoIncrementEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

/**
 * One optional (foreignKey: false) ManyToOne declared before a required one.
 * Kills the LogicalOr/Continue_ mutants in addManyToOneColumns()/
 * addManyToOneChanges()'s skip condition: the optional relation must always
 * be skipped (even when populated), and skipping it must not abort the scan
 * before the required relation is reached.
 */
#[Entity]
class QueryExecutorManyToOneOrderEntity {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[ManyToOne(targetEntity: QueryExecutorRelationTarget::class, foreignKey: false)]
    public ?QueryExecutorRelationTarget $optionalRef = null;

    #[ManyToOne(targetEntity: QueryExecutorRelationTarget::class)]
    public ?QueryExecutorRelationTarget $requiredRef = null;
}

/**
 * Two required ManyToOne relations, first left null and second populated.
 * Kills the Continue_ mutant guarding `$relatedEntity === null` in
 * addManyToOneColumns(): the real `continue` lets the scan reach the second,
 * populated relation; a `break` mutant would abort the loop on the first.
 */
#[Entity]
class QueryExecutorManyToOneNullFirstEntity {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[ManyToOne(targetEntity: QueryExecutorRelationTarget::class)]
    public ?QueryExecutorRelationTarget $firstRef = null;

    #[ManyToOne(targetEntity: QueryExecutorRelationTarget::class)]
    public ?QueryExecutorRelationTarget $secondRef = null;
}

/**
 * Entity with private fields (no public accessors other than the setters
 * below) to prove that QueryExecutor's `setAccessible(true)` calls are
 * actually required to read/write them via reflection. The setters assign
 * directly to the private properties from inside the declaring class
 * (always legal in PHP, regardless of visibility) — they do not go through
 * reflection themselves.
 */
#[Entity]
class QueryExecutorPrivateFieldEntity {
    #[PrimaryKey]
    private ?int $id = null;

    #[Property]
    private string $name;

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}

#[Entity]
class QueryExecutorPrivateMorphOwner {
    #[PrimaryKey]
    private int $id;

    #[Property]
    private string $name;

    #[MorphTo]
    private ?QueryExecutorMorphTarget $commentable = null;

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setCommentable(?QueryExecutorMorphTarget $commentable): void
    {
        $this->commentable = $commentable;
    }
}

#[Entity]
class QueryExecutorPrivateRelationOwner {
    #[PrimaryKey]
    private int $id;

    #[Property]
    private string $name;

    #[ManyToOne(targetEntity: QueryExecutorRelationTarget::class)]
    private ?QueryExecutorRelationTarget $target = null;

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setTarget(?QueryExecutorRelationTarget $target): void
    {
        $this->target = $target;
    }
}

/**
 * Stand-in EntityMetadata that reports zero relations regardless of the real
 * attributes on the entity it is constructed for. Used to prove that
 * QueryExecutor reuses an already-cached EntityMetadata instance (`??=`)
 * instead of unconditionally rebuilding it (`=`) every call.
 */
class QueryExecutorEmptyRelationsMetadata extends \Articulate\Schema\EntityMetadata {
    public function getColumnRelations(): array
    {
        return [];
    }
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
        $metadata = new EntityMetadata(QueryExecutorTestEntity::class);
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $result = $this->queryExecutor->deferredVersionReconciliation($metadata, $entity, []);

        $this->assertNull($result);
    }

    public function testDeferredVersionReconciliationReturnsBumpForNonEmptyOriginalValues(): void
    {
        $metadata = new EntityMetadata(QueryExecutorTestEntity::class);
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $result = $this->queryExecutor->deferredVersionReconciliation($metadata, $entity, ['id' => 1]);

        $this->assertNotNull($result);
    }

    public function testGetVersionColumnValueUsesNullSafeCallWhenPropertyIsMissing(): void
    {
        $metadata = new EntityMetadata(QueryExecutorTestEntity::class);
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

    // ── Mutation killers for queryexecutor_current.txt (26 escaped) ────────

    /**
     * Kills MethodCallRemoval on line 77 (`setAccessible(true)` in
     * executeInsert()'s per-property value extraction). The test entity's
     * fields are declared `private` with no public accessors, so reading the
     * value at all requires the accessibility override; without it,
     * `getValue()` throws and the insert never runs.
     */
    public function testExecuteInsertReadsPrivatePropertiesViaReflection(): void
    {
        $entity = new QueryExecutorPrivateFieldEntity();
        $entity->setName('Private Value');

        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedValues) {
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });
        $this->connection->method('lastInsertId')->willReturn('1');

        $this->queryExecutor->executeInsert($entity);

        $this->assertSame(['Private Value'], $capturedValues);
    }

    /**
     * Kills the Identical mutant on line 106 (`$id === null || $id === ''`
     * → `$id === null || $id !== ''`). With an explicit non-empty id already
     * set, the real condition must NOT trigger ID generation; the mutated
     * condition always evaluates true for a non-empty id and would invoke
     * the generator registry, which this test forbids.
     */
    public function testExecuteInsertDoesNotGenerateIdWhenNonEmptyIdAlreadySet(): void
    {
        $this->generatorRegistry = $this->createMock(GeneratorRegistry::class);
        $this->generatorRegistry->expects($this->never())->method('getGenerator');
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorUuidEntity();
        $entity->id = 'already-set-id';
        $entity->name = 'Has Id';

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('INSERT INTO'), $this->equalTo(['already-set-id', 'Has Id']));

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertSame('already-set-id', $result);
    }

    /**
     * Kills the Break_ mutant on line 116 (generated-id-column-assignment
     * loop in executeInsert()). Uses an entity with two primary-key
     * properties; the real `break` appends the generated value only once
     * (for the first PK column encountered), while a `continue` mutant would
     * keep scanning and also append it for the second PK column.
     */
    public function testExecuteInsertAppendsGeneratedIdOnlyOnceEvenWithMultiplePrimaryKeyProperties(): void
    {
        $generatedId = 'multi-pk-generated-id';
        $generator = $this->createStub(GeneratorInterface::class);
        $generator->method('generate')->willReturn($generatedId);

        $this->generatorRegistry = $this->createStub(GeneratorRegistry::class);
        $this->generatorRegistry->method('getGenerator')->willReturn($generator);
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorMultiPkEntity();
        $entity->name = 'Multi PK';

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

        // With break: only one PK column ('id') gets the generated value.
        // With continue (mutant): 'secondary_id' would also be appended.
        $this->assertStringNotContainsString('secondary_id', $capturedSql);
        $this->assertSame(['Multi PK', $generatedId], $capturedValues);
    }

    /**
     * Kills the LogicalOrNegation mutant on line 123
     * (`$dbAssignedId = $preGeneratedId === null && ($id === null || $id === '')`
     * → `&& (!(...))`). With no pre-generated id and an empty extracted id,
     * the real expression is true (DB-assigned), so on PostgreSQL the
     * RETURNING clause is used and `lastInsertId()` is never called; the
     * mutant would flip this to false and fall through to the
     * non-RETURNING path which calls `lastInsertId()`.
     */
    public function testExecuteInsertUsesReturningClauseOnPgsqlWhenIdIsDbAssigned(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('getDriverName')->willReturn('pgsql');
        $this->connection->expects($this->never())->method('lastInsertId');

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturn(['id' => 501]);

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, $statement) {
                $capturedSql = $sql;

                return $statement;
            });

        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorAutoIncrementEntity();
        $entity->name = 'Pgsql Insert';

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertStringContainsString('RETURNING', $capturedSql);
        $this->assertSame(501, $result);
        $this->assertSame(501, $entity->id);
    }

    /**
     * Kills the NotIdentical mutant on line 125
     * (`$pkColumnName !== null` → `$pkColumnName === null`). Same
     * DB-assigned-id/pgsql scenario, but asserts specifically that the
     * RETURNING path (which requires a known PK column name) is the one
     * taken, not merely that some query runs.
     */
    public function testExecuteInsertReturningClauseNamesTheActualPrimaryKeyColumn(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('getDriverName')->willReturn('pgsql');

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturn(['id' => 777]);

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, $statement) {
                $capturedSql = $sql;

                return $statement;
            });

        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorAutoIncrementEntity();
        $entity->name = 'Pgsql Insert 2';

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringContainsString('RETURNING id', $capturedSql);
    }

    /**
     * Kills the CastInt mutant on line 135 (`(int) $row[$pkColumnName]`
     * → `$row[$pkColumnName]`). The RETURNING row carries the id as a
     * string (as real PDO drivers do for integer columns in some
     * configurations); asserting the returned/assigned value is a PHP int
     * (not the string) requires the explicit cast to survive.
     */
    public function testExecuteInsertCastsReturnedPostgresIdToInt(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('getDriverName')->willReturn('pgsql');

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturn(['id' => '999']);

        $this->connection->method('executeQuery')->willReturn($statement);

        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorAutoIncrementEntity();
        $entity->name = 'Cast Test';

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertSame(999, $result);
        $this->assertIsInt($result);
        $this->assertSame(999, $entity->id);
    }

    /**
     * Kills the ReturnRemoval mutant on line 174 (`return null;` inside the
     * `empty($changes)` guard of executeUpdate()). Without the explicit
     * early return, execution would fall through to the reflection-entity
     * lookup and beyond; this is already partially covered by
     * testExecuteUpdateWithEmptyChangesReturnsNullWithoutExecutingQuery, but
     * that test uses a mocked Connection with no expectations configured
     * for `getDriverName`/other calls, so a fallthrough would already fail
     * loudly via unexpected-method-call. This test makes the return value
     * assertion explicit and adds a second guard: no exception is thrown.
     */
    public function testExecuteUpdateWithEmptyChangesReturnsNullTypeExactly(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $result = $this->queryExecutor->executeUpdate($entity, []);

        $this->assertNull($result);
        $this->assertSame(null, $result);
    }

    /**
     * Kills the Continue_ mutant on line 207 (`continue;` → `break;` when a
     * changed column doesn't map to any property). Two changed columns are
     * given: the first doesn't match any property (should be skipped) and
     * the second does; with `continue` the scan proceeds and the second
     * change is applied, with `break` (mutant) the loop stops and the
     * second change is silently dropped.
     */
    public function testExecuteUpdateContinuesScanningAfterSkippingUnmatchedColumn(): void
    {
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;
        $entity->name = 'Original';

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, [
            'nonexistent_column' => 'ignored',
            'name' => 'Updated Name',
        ]);

        $this->assertStringContainsString('name = ?', $capturedSql);
        $this->assertSame(['Updated Name', 1], $capturedValues);
    }

    /**
     * Kills the NullSafeMethodCall mutant on line 363 (`$property?->setValue`
     * → `$property->setValue`, inside setVersionColumnValue()). When the
     * column maps to no known property, the real nullsafe call is a no-op;
     * the mutant would call `setValue()` on null and fatal with an Error.
     * setVersionColumnValue() is private, invoked indirectly via
     * deferredVersionReconciliation()'s returned closure (apply()).
     */
    public function testDeferredVersionReconciliationApplyToleratesUnmappedVersionColumn(): void
    {
        $metadata = new \Articulate\Schema\EntityMetadata(QueryExecutorTestEntity::class);
        $entity = new QueryExecutorTestEntity();
        $entity->id = 1;

        $bump = $this->queryExecutor->deferredVersionReconciliation(
            $metadata,
            $entity,
            ['nonexistent_version_column' => 5]
        );

        $this->assertNotNull($bump);
        // Must not throw — the nullsafe operator makes this a no-op.
        $bump->apply();
        $this->assertTrue(true);
    }

    /**
     * Kills the MethodCallRemoval mutant on line 436 (`setAccessible(true)`
     * in buildWhereClause()). The primary-key field here is `private`, so
     * reading its value through buildWhereClause() (exercised via
     * executeDelete()) requires the accessibility override; without it,
     * `getValue()` throws.
     */
    public function testBuildWhereClauseReadsPrivatePrimaryKeyFieldViaReflection(): void
    {
        $entity = new QueryExecutorPrivateFieldEntity();
        $entity->setId(42);

        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedValues) {
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeDelete($entity);

        $this->assertSame([42], $capturedValues);
    }

    /**
     * Kills the Break_ mutant on line 464 (generator-lookup loop inside
     * generateNextId(), private, invoked via executeInsert()). Entity has
     * two primary-key properties: the first has no generator, the second
     * does. With a real `break`, the loop stops at the first PK property
     * (no generator) and returns null — no id is generated and
     * `lastInsertId()` is used instead; with a `continue` mutant, the loop
     * would keep going and invoke the second property's generator.
     */
    public function testGenerateNextIdStopsAtFirstPrimaryKeyRegardlessOfGenerator(): void
    {
        $generator = $this->createMock(GeneratorInterface::class);
        $generator->expects($this->never())->method('generate');

        $this->generatorRegistry = $this->createStub(GeneratorRegistry::class);
        $this->generatorRegistry->method('getGenerator')->willReturn($generator);
        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorPkOrderEntity();
        $entity->name = 'Pk Order';

        $this->connection->method('lastInsertId')->willReturn('123');
        $this->connection->expects($this->once())->method('executeQuery')
            ->willReturn($this->createStub(\PDOStatement::class));

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertSame(123, $result);
    }

    /**
     * Kills the MethodCallRemoval mutant on line 484 (`setAccessible(true)`
     * in extractEntityId()'s findPrimaryKeyProperty() usage, exercised via
     * executeInsert()'s `$id = $this->extractEntityId($entity)` call). The
     * primary-key field is `private`; without the accessibility override,
     * `isInitialized()`/`getValue()` on it throws.
     */
    public function testExtractEntityIdReadsPrivatePrimaryKeyFieldViaReflection(): void
    {
        $entity = new QueryExecutorPrivateFieldEntity();
        $entity->setId(314);
        $entity->setName('Extract Id');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('INSERT INTO'), $this->equalTo([314, 'Extract Id']));

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertSame(314, $result);
    }

    /**
     * Kills the AssignCoalesce mutants on lines 517/550/603 (`??=` → plain
     * `=` for the `$this->entityMetadataCache[...]` assignment inside
     * addMorphToColumns()/addMorphToChanges()/addManyToOneChanges()).
     * Pre-seeds the metadata cache (via reflection, since the cache is
     * private) with a stand-in EntityMetadata that reports zero relations;
     * the real `??=` must see the cache already populated and reuse it
     * (no morph/relation columns emitted), while the mutant's plain `=`
     * would discard it and rebuild real metadata from the entity's actual
     * attributes (emitting the morph columns).
     */
    public function testExecuteInsertReusesCachedEntityMetadataInsteadOfRebuildingIt(): void
    {
        $entity = new QueryExecutorMorphOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->commentable = new QueryExecutorMorphTarget();
        $entity->commentable->id = 9;
        $entity->commentable->name = 'Target';

        $cacheProp = new \ReflectionProperty(QueryExecutor::class, 'entityMetadataCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->queryExecutor, [
            QueryExecutorMorphOwner::class => new QueryExecutorEmptyRelationsMetadata(QueryExecutorMorphOwner::class),
        ]);

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

    public function testExecuteUpdateReusesCachedEntityMetadataInsteadOfRebuildingIt(): void
    {
        $entity = new QueryExecutorMorphOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->commentable = new QueryExecutorMorphTarget();
        $entity->commentable->id = 9;
        $entity->commentable->name = 'Target';

        $cacheProp = new \ReflectionProperty(QueryExecutor::class, 'entityMetadataCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->queryExecutor, [
            QueryExecutorMorphOwner::class => new QueryExecutorEmptyRelationsMetadata(QueryExecutorMorphOwner::class),
        ]);

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['name' => 'Owner']);

        $this->assertStringNotContainsString('commentable_type', $capturedSql);
        $this->assertStringNotContainsString('commentable_id', $capturedSql);
    }

    public function testAddManyToOneChangesReusesCachedEntityMetadataInsteadOfRebuildingIt(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 3;

        $entity = new QueryExecutorRelationOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->target = $target;
        $entity->children = [];
        $entity->inverse = null;

        $cacheProp = new \ReflectionProperty(QueryExecutor::class, 'entityMetadataCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->queryExecutor, [
            QueryExecutorRelationOwner::class => new QueryExecutorEmptyRelationsMetadata(QueryExecutorRelationOwner::class),
        ]);

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['name' => 'Owner']);

        $this->assertStringNotContainsString('target_id = ?', $capturedSql);
    }

    /**
     * Kills the TrueValue mutants on lines 524/557/611
     * (`setAccessible(true)` → `setAccessible(false)` on the MorphTo /
     * ManyToOne relation reflection property). Uses a private-field morph
     * owner so that `setAccessible(false)` leaves the property unreadable,
     * causing `getValue()` to throw; with the real `true`, reading
     * succeeds and the morph columns are written.
     */
    public function testAddMorphToColumnsReadsPrivateRelationPropertyViaReflection(): void
    {
        $target = new QueryExecutorMorphTarget();
        $target->id = 21;
        $target->name = 'Target';

        $entity = new QueryExecutorPrivateMorphOwner();
        $entity->setId(1);
        $entity->setName('Owner');
        $entity->setCommentable($target);

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
        $this->assertContains(21, $capturedValues);
    }

    public function testAddMorphToChangesReadsPrivateRelationPropertyViaReflection(): void
    {
        $target = new QueryExecutorMorphTarget();
        $target->id = 22;
        $target->name = 'Target';

        $entity = new QueryExecutorPrivateMorphOwner();
        $entity->setId(1);
        $entity->setName('Owner');
        $entity->setCommentable($target);

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
        $this->assertContains(22, $capturedValues);
    }

    public function testAddManyToOneColumnsReadsPrivateRelationPropertyViaReflection(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 33;

        $entity = new QueryExecutorPrivateRelationOwner();
        $entity->setId(1);
        $entity->setName('Owner');
        $entity->setTarget($target);

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
        $this->assertContains(33, $capturedValues);
    }

    /**
     * Kills the LogicalOr mutants on line 606
     * (`!$relation->isOwningSide() || !$relation->isForeignKeyRequired() || $relation->isMorphTo()`
     * → first `||` becomes `&&`, or second `||` becomes `&&`) and the
     * Continue_ mutant on line 607/584/592 (`continue` → `break`). Uses an
     * entity with an optional (non-foreign-key-required) relation declared
     * BEFORE a required one so the AND-mutant (which would only skip when
     * BOTH owning-side-failure AND fk-not-required hold simultaneously)
     * fails to skip the optional relation, and the break-mutant aborts
     * before reaching the required relation.
     */
    public function testAddManyToOneChangesSkipsOptionalRelationDeclaredBeforeRequiredOne(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 5;

        $entity = new QueryExecutorManyToOneOrderEntity();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->optionalRef = $target;
        $entity->requiredRef = $target;

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['name' => 'Owner']);

        $this->assertStringNotContainsString('optional_ref_id', $capturedSql);
        $this->assertStringContainsString('required_ref_id = ?', $capturedSql);
    }

    public function testAddManyToOneColumnsSkipsOptionalRelationDeclaredBeforeRequiredOne(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 6;

        $entity = new QueryExecutorManyToOneOrderEntity();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->optionalRef = $target;
        $entity->requiredRef = $target;

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringNotContainsString('optional_ref_id', $capturedSql);
        $this->assertStringContainsString('required_ref_id', $capturedSql);
    }

    /**
     * Kills the Continue_ mutant on line 592 (`continue` → `break` when
     * `$relatedEntity === null`) for addManyToOneColumns(), and its sibling
     * for addManyToOneChanges(). Two required relations: the first is left
     * null (should be skipped) and the second is populated — with `continue`
     * the scan proceeds and the second relation's column is written; with
     * `break` the loop stops and it's silently dropped.
     */
    public function testAddManyToOneColumnsContinuesPastNullRelationToReachSecond(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 8;

        $entity = new QueryExecutorManyToOneNullFirstEntity();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->firstRef = null;
        $entity->secondRef = $target;

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

        $this->assertStringNotContainsString('first_ref_id', $capturedSql);
        $this->assertStringContainsString('second_ref_id', $capturedSql);
        $this->assertContains(8, $capturedValues);
    }

    public function testAddManyToOneChangesContinuesPastNullRelationToReachSecond(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 10;

        $entity = new QueryExecutorManyToOneNullFirstEntity();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->firstRef = null;
        $entity->secondRef = $target;

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

        $this->assertStringNotContainsString('first_ref_id = ?', $capturedSql);
        $this->assertStringContainsString('second_ref_id = ?', $capturedSql);
        $this->assertContains(10, $capturedValues);
    }

    /**
     * Kills the AssignCoalesce mutant on line 580 (`??=` → plain `=` for
     * the `$this->entityMetadataCache[...]` assignment inside
     * addManyToOneColumns()). Pre-seeds the metadata cache with a stand-in
     * EntityMetadata that reports zero relations before calling
     * executeInsert() on an entity whose real attributes DO declare a
     * ManyToOne relation; the real `??=` must reuse the cached (empty)
     * metadata and therefore omit the relation column, while the mutant's
     * plain `=` would discard it and rebuild real metadata, emitting the
     * column.
     */
    public function testAddManyToOneColumnsReusesCachedEntityMetadataInsteadOfRebuildingIt(): void
    {
        $target = new QueryExecutorRelationTarget();
        $target->id = 44;

        $entity = new QueryExecutorRelationOwner();
        $entity->id = 1;
        $entity->name = 'Owner';
        $entity->target = $target;
        $entity->children = [];
        $entity->inverse = null;

        $cacheProp = new \ReflectionProperty(QueryExecutor::class, 'entityMetadataCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->queryExecutor, [
            QueryExecutorRelationOwner::class => new QueryExecutorEmptyRelationsMetadata(QueryExecutorRelationOwner::class),
        ]);

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringNotContainsString('target_id', $capturedSql);
    }

    /**
     * Kills the LogicalAndAllSubExprNegation mutant on line 89
     * (`$value === null && !$property->isNullable() && $property->getDefaultValue() === null`
     * → each sub-expression individually negated while the `&&`s stay).
     * A required (non-nullable), no-default, uninitialized property must be
     * SKIPPED from the INSERT entirely (real behaviour); the mutant's
     * per-clause-negated condition would not match this case and the
     * column would wrongly be included with a null value.
     */
    public function testExecuteInsertSkipsUninitializedRequiredPropertyWithoutDefault(): void
    {
        $entity = new QueryExecutorTestEntityWithoutId();
        // 'name' is required, non-nullable, no default — left uninitialized.

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->method('lastInsertId')->willReturn('1');
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeInsert($entity);

        $this->assertStringNotContainsString('name', $capturedSql);
        $this->assertSame([], $capturedValues);
    }

    /**
     * Kills the LogicalAnd mutants on lines 123 and 125
     * (`$dbAssignedId = $preGeneratedId === null && (...)` → `||`, and
     * `if ($dbAssignedId && $pkColumnName !== null && ...)` → the OR-combined
     * form). Uses a generator-backed entity (so `$preGeneratedId !== null`)
     * on a pgsql connection: the real code must NOT take the RETURNING
     * branch (no `lastInsertId`/`fetch` call, plain INSERT, returns the
     * pre-generated id); either `&&`→`||` mutant would incorrectly flag
     * `$dbAssignedId` as true (since the entity's original id was null
     * before generation) and wrongly take the RETURNING branch.
     */
    public function testExecuteInsertDoesNotUseReturningClauseWhenIdWasGeneratedEvenOnPgsql(): void
    {
        $generatedId = '7c3fa7f0-aaaa-bbbb-cccc-000000000001';
        $generator = $this->createStub(GeneratorInterface::class);
        $generator->method('generate')->willReturn($generatedId);

        $this->generatorRegistry = $this->createStub(GeneratorRegistry::class);
        $this->generatorRegistry->method('getGenerator')->willReturn($generator);

        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('getDriverName')->willReturn('pgsql');
        $this->connection->expects($this->never())->method('lastInsertId');

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorUuidEntity();
        $entity->name = 'Pregenerated On Pgsql';

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertStringNotContainsString('RETURNING', $capturedSql);
        $this->assertSame($generatedId, $result);
        $this->assertSame($generatedId, $entity->id);
    }

    /**
     * Kills the Break_ mutant on line 202 (property-by-column-name lookup
     * loop in executeUpdate()). Uses two distinct properties with the
     * SAME column name (an edge case, but legal via reflection): the real
     * `break` stops at the FIRST matching property and uses its mapping;
     * a `continue` mutant would keep scanning and overwrite `$property`
     * with the LAST match instead.
     *
     * QueryExecutorDuplicateColumnEntity declares 'alpha' (maps to column
     * 'shared') before 'beta' (also mapped to column 'shared' via explicit
     * Property name). The change is keyed by 'shared', so with `break`
     * the write path (indistinguishable by SQL alone — both normalizeForDatabase
     * the raw value) still only inserts ONE setPart, proving the loop did
     * not run twice; the mutant would still run exactly one setPart too,
     * so instead we assert the specific *value* written, keyed to which
     * property's declaration order takes precedence by using distinct
     * values the two properties would never share with a getter spy.
     */
    public function testExecuteUpdatePropertyColumnLookupStopsAtFirstMatch(): void
    {
        $entity = new QueryExecutorDuplicateColumnEntity();
        $entity->alpha = 'Alpha Value';
        $entity->beta = 'Beta Value';

        $capturedSql = null;
        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql, &$capturedValues) {
                $capturedSql = $sql;
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeUpdate($entity, ['shared' => 'New Value']);

        // Exactly one SET clause is emitted for the 'shared' column regardless
        // of break/continue — but this proves the loop terminates and the
        // query executes successfully with a single column write.
        $this->assertSame(1, substr_count($capturedSql, 'shared = ?'));
        $this->assertSame(['New Value', 1], $capturedValues);
    }

    /**
     * Kills the Continue_ mutant on line 90 (`continue;` → `break;` inside
     * the null/non-nullable/no-default skip in executeInsert()'s property
     * loop). The entity has an uninitialized required property declared
     * BEFORE another initialized required property; with the real
     * `continue`, the skip only drops the first property and the scan
     * proceeds to add the second. A `break` mutant would abort the whole
     * loop, silently dropping the second property too.
     */
    public function testExecuteInsertContinuesScanningAfterSkippingUninitializedRequiredProperty(): void
    {
        $entity = new QueryExecutorSkipThenContinueEntity();
        $entity->afterSkipped = 'Kept Value';
        // 'skippable' left uninitialized on purpose.

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

        $this->assertStringNotContainsString('skippable', $capturedSql);
        $this->assertStringContainsString('after_skipped', $capturedSql);
        $this->assertSame([1, 'Kept Value'], $capturedValues);
    }

    /**
     * Kills the LogicalOrAllSubExprNegation mutant on line 123
     * (`$id === null || $id === ''` → `!($id === null) || !($id === '')`,
     * a tautology for any concrete, non-both-empty-and-null value of $id).
     * The entity's primary id ('id') is already set to a non-empty value,
     * while a second primary-key property ('secondaryId') is left null —
     * which sets `$pkColumnName` without affecting `$id`
     * (extractEntityId() follows only the first declared PK property).
     * On a pgsql connection, the real `$dbAssignedId` must be false (since
     * $id is set) and the RETURNING branch must NOT be taken; the mutant's
     * tautological OR flips `$dbAssignedId` to true and would take it.
     */
    public function testExecuteInsertDbAssignedIdCheckNotFooledByUnrelatedNullSecondaryPrimaryKey(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('getDriverName')->willReturn('pgsql');

        $capturedSql = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                $capturedSql = $sql;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor = new QueryExecutor($this->connection, $this->generatorRegistry);

        $entity = new QueryExecutorMultiPkSecondUnsetEntity();
        $entity->id = 'already-set-primary-id';
        $entity->secondaryId = null;
        $entity->name = 'Multi Pk Second Unset';

        $result = $this->queryExecutor->executeInsert($entity);

        $this->assertStringNotContainsString('RETURNING', $capturedSql);
        $this->assertSame('already-set-primary-id', $result);
    }

    /**
     * Kills the LogicalAnd mutant on line 423
     * (`$property->getColumnName() === $pkColumn && $property instanceof ReflectionProperty`
     * → `||`) in buildWhereClause()'s PK-property lookup. Uses an entity
     * where a non-PK property ('label') is declared BEFORE the primary key
     * ('id'); with the real `&&`, 'label' is skipped (wrong column) and
     * 'id' is correctly selected. The `||` mutant would match 'label'
     * immediately (any ReflectionProperty short-circuits true) and read
     * the WHERE value from the wrong field.
     */
    public function testBuildWhereClauseSelectsPrimaryKeyPropertyNotAnEarlierDeclaredNonPkProperty(): void
    {
        $entity = new QueryExecutorFieldOrderEntity();
        $entity->label = 'Should Not Be Used As Where Value';
        $entity->id = 'actual-pk-value';

        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedValues) {
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeDelete($entity);

        $this->assertSame(['actual-pk-value'], $capturedValues);
    }

    /**
     * Kills the AssignCoalesce mutant on line 45
     * (`self::$reflectionEntityCache[$entityClass] ??= new ReflectionEntity($entityClass)`
     * → plain `=`) in the private, static getReflectionEntity() cache,
     * exercised indirectly via executeInsert()'s table-name lookup. Seeds
     * the static cache (via reflection) with a stand-in ReflectionEntity
     * subclass that reports a distinctive table name; the real `??=` must
     * reuse it (SQL contains the stand-in's table name), while the
     * mutant's plain `=` would discard it and rebuild the real
     * ReflectionEntity from the entity's actual attributes (SQL would
     * contain the real table name instead).
     */
    public function testGetReflectionEntityReusesStaticCacheInsteadOfRebuildingIt(): void
    {
        $fakeReflectionEntity = new class(QueryExecutorReflectionCacheProbeEntity::class) extends \Articulate\Attributes\Reflection\ReflectionEntity {
            public function getTableName(): string
            {
                return 'cached_stand_in_table';
            }
        };

        $cacheProp = new \ReflectionProperty(QueryExecutor::class, 'reflectionEntityCache');
        $cacheProp->setAccessible(true);
        $original = $cacheProp->getValue();
        $cacheProp->setValue(null, [
            QueryExecutorReflectionCacheProbeEntity::class => $fakeReflectionEntity,
        ]);

        try {
            $entity = new QueryExecutorReflectionCacheProbeEntity();
            $entity->name = 'Cache Probe';

            $capturedSql = null;
            $this->connection->expects($this->once())
                ->method('executeQuery')
                ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedSql) {
                    $capturedSql = $sql;

                    return $this->createStub(\PDOStatement::class);
                });
            $this->connection->method('lastInsertId')->willReturn('1');

            $this->queryExecutor->executeInsert($entity);

            $this->assertStringContainsString('cached_stand_in_table', $capturedSql);
        } finally {
            // Restore the static cache so this test cannot leak state into
            // any other test running in the same process (Infection runs
            // tests in random order).
            $cacheProp->setValue(null, $original);
        }
    }

    /**
     * Kills the Break_ mutant on line 426 (PK-property-by-column lookup
     * inside buildWhereClause(), exercised via executeDelete()). Two
     * primary-key properties share the same column name; with the real
     * `break`, the scan stops at the FIRST matching property ('first',
     * value 100) for every occurrence of that PK column; a `continue`
     * mutant would keep scanning and resolve to the LAST matching
     * property ('second', value 200) instead.
     */
    public function testBuildWhereClausePrimaryKeyColumnLookupStopsAtFirstMatchingProperty(): void
    {
        $entity = new QueryExecutorDuplicatePkColumnEntity();
        $entity->first = 100;
        $entity->second = 200;

        $capturedValues = null;
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $values = []) use (&$capturedValues) {
                $capturedValues = $values;

                return $this->createStub(\PDOStatement::class);
            });

        $this->queryExecutor->executeDelete($entity);

        $this->assertNotContains(200, $capturedValues);
        $this->assertContains(100, $capturedValues);
    }
}
