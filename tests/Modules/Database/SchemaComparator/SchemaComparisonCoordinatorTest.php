<?php

namespace Articulate\Tests\Modules\Database\SchemaComparator;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\Index;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionMorphToMany;
use Articulate\Modules\Database\SchemaComparator\Comparators\EntityTableComparator;
use Articulate\Modules\Database\SchemaComparator\Comparators\MappingTableComparator;
use Articulate\Modules\Database\SchemaComparator\Models\ForeignKeyCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\TableCompareResult;
use Articulate\Modules\Database\SchemaComparator\RelationDefinitionCollector;
use Articulate\Modules\Database\SchemaComparator\SchemaComparisonCoordinator;
use Articulate\Modules\Database\SchemaReader\DatabaseSchemaReaderInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SchemaComparisonCoordinatorTest extends TestCase {
    private DatabaseSchemaReaderInterface $schemaReader;

    private RelationDefinitionCollector $relationDefinitionCollector;

    private EntityTableComparator $entityTableComparator;

    private MappingTableComparator $mappingTableComparator;

    private SchemaComparisonCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->schemaReader = $this->createMock(DatabaseSchemaReaderInterface::class);
        $this->relationDefinitionCollector = $this->createMock(RelationDefinitionCollector::class);
        $this->entityTableComparator = $this->createMock(EntityTableComparator::class);
        $this->mappingTableComparator = $this->createMock(MappingTableComparator::class);

        $this->coordinator = new SchemaComparisonCoordinator(
            $this->schemaReader,
            $this->relationDefinitionCollector,
            $this->entityTableComparator,
            $this->mappingTableComparator,
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSchemaComparisonCoordinatorCanBeInstantiated(): void
    {
        $this->assertInstanceOf(SchemaComparisonCoordinator::class, $this->coordinator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllWithEmptyEntitiesReturnsEmptyIterable(): void
    {
        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([])
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([])
            ->willReturn([]);

        $result = $this->coordinator->compareAll([]);

        $this->assertIsIterable($result);
        $results = iterator_to_array($result);
        $this->assertEmpty($results);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllWithEntitiesThatCreateTables(): void
    {
        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(true);
        $entity->method('getTableName')->willReturn('users');

        $createResult = new TableCompareResult('users', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([$entity]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->entityTableComparator->expects($this->once())
            ->method('compareEntityTable')
            ->with([$entity], [], 'users')
            ->willReturn($createResult);

        $result = $this->coordinator->compareAll([$entity]);

        $results = iterator_to_array($result);
        $this->assertCount(1, $results);
        $this->assertSame($createResult, $results[0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllWithExistingTablesToDelete(): void
    {
        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn(['old_table', 'another_table']);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([])
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([])
            ->willReturn([]);

        $result = $this->coordinator->compareAll([]);

        $results = iterator_to_array($result);
        $this->assertCount(2, $results);

        $tableNames = array_map(fn ($result) => $result->name, $results);
        $this->assertContains('old_table', $tableNames);
        $this->assertContains('another_table', $tableNames);

        foreach ($results as $result) {
            $this->assertEquals(TableCompareResult::OPERATION_DELETE, $result->operation);
        }
    }

    public function testCompareAllWithManyToManyRelations(): void
    {
        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(true);
        $entity->method('getTableName')->willReturn('users');

        $manyToManyDefinition = [
            'tableName' => 'user_posts',
            'ownerTable' => 'users',
            'targetTable' => 'posts',
            'ownerJoinColumn' => 'user_id',
            'targetJoinColumn' => 'post_id',
            'ownerReferencedColumn' => 'id',
            'targetReferencedColumn' => 'id',
            'extraProperties' => [],
            'primaryColumns' => ['user_id', 'post_id'],
        ];

        $mappingResult = new TableCompareResult('user_posts', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([$entity]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([$entity])
            ->willReturn(['user_posts' => $manyToManyDefinition]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->entityTableComparator->expects($this->once())
            ->method('compareEntityTable')
            ->with([$entity], [], 'users')
            ->willReturn(null);

        $this->mappingTableComparator->expects($this->once())
            ->method('compareManyToManyTable')
            ->with($manyToManyDefinition, [])
            ->willReturn($mappingResult);

        $result = $this->coordinator->compareAll([$entity]);

        $results = iterator_to_array($result);
        $this->assertCount(1, $results);
        $this->assertSame($mappingResult, $results[0]);
    }

    public function testCompareAllWithMorphToManyRelations(): void
    {
        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(true);
        $entity->method('getTableName')->willReturn('posts');

        $morphToManyDefinition = [
            'tableName' => 'taggables',
            'morphName' => 'taggable',
            'typeColumn' => 'taggable_type',
            'idColumn' => 'taggable_id',
            'targetColumn' => 'tag_id',
            'targetTable' => 'tags',
            'targetReferencedColumn' => 'id',
            'extraProperties' => [],
            'primaryColumns' => ['taggable_type', 'taggable_id', 'tag_id'],
            'relations' => [$this->createStub(ReflectionMorphToMany::class)],
        ];

        $mappingResult = new TableCompareResult('taggables', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([$entity]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([$entity])
            ->willReturn(['taggables' => $morphToManyDefinition]);

        $this->entityTableComparator->expects($this->once())
            ->method('compareEntityTable')
            ->with([$entity], [], 'posts')
            ->willReturn(null);

        $this->mappingTableComparator->expects($this->once())
            ->method('compareMorphToManyTable')
            ->with($morphToManyDefinition, [])
            ->willReturn($mappingResult);

        $result = $this->coordinator->compareAll([$entity]);

        $results = iterator_to_array($result);
        $this->assertCount(1, $results);
        $this->assertSame($mappingResult, $results[0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllGroupsEntitiesByTableName(): void
    {
        $entity1 = $this->createStub(ReflectionEntity::class);
        $entity1->method('isEntity')->willReturn(true);
        $entity1->method('getTableName')->willReturn('users');

        $entity2 = $this->createStub(ReflectionEntity::class);
        $entity2->method('isEntity')->willReturn(true);
        $entity2->method('getTableName')->willReturn('users');

        $createResult = new TableCompareResult('users', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([$entity1, $entity2]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([$entity1, $entity2])
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([$entity1, $entity2])
            ->willReturn([]);

        $this->entityTableComparator->expects($this->once())
            ->method('compareEntityTable')
            ->with([$entity1, $entity2], [], 'users')
            ->willReturn($createResult);

        $result = $this->coordinator->compareAll([$entity1, $entity2]);

        $results = iterator_to_array($result);
        $this->assertCount(1, $results);
        $this->assertSame($createResult, $results[0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllFiltersOutNonEntityClasses(): void
    {
        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(false); // Not an entity

        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([$entity]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->entityTableComparator->expects($this->never())
            ->method('compareEntityTable');

        $result = $this->coordinator->compareAll([$entity]);

        $results = iterator_to_array($result);
        $this->assertEmpty($results);
    }

    public function testCompareAllHandlesMixedOperations(): void
    {
        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(true);
        $entity->method('getTableName')->willReturn('users');

        $manyToManyDefinition = [
            'tableName' => 'user_posts',
            'ownerTable' => 'users',
            'targetTable' => 'posts',
            'ownerJoinColumn' => 'user_id',
            'targetJoinColumn' => 'post_id',
            'ownerReferencedColumn' => 'id',
            'targetReferencedColumn' => 'id',
            'extraProperties' => [],
            'primaryColumns' => ['user_id', 'post_id'],
        ];

        $entityResult = new TableCompareResult('users', TableCompareResult::OPERATION_CREATE);
        $mappingResult = new TableCompareResult('user_posts', TableCompareResult::OPERATION_CREATE);
        $deleteResult = new TableCompareResult('old_table', TableCompareResult::OPERATION_DELETE);

        $this->schemaReader->expects($this->once())
            ->method('getTables')
            ->willReturn(['old_table']);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('validateRelations')
            ->with([$entity]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectManyToManyTables')
            ->with([$entity])
            ->willReturn(['user_posts' => $manyToManyDefinition]);

        $this->relationDefinitionCollector->expects($this->once())
            ->method('collectMorphToManyTables')
            ->with([$entity])
            ->willReturn([]);

        $this->entityTableComparator->expects($this->once())
            ->method('compareEntityTable')
            ->with([$entity], ['old_table'], 'users')
            ->willReturn($entityResult);

        $this->mappingTableComparator->expects($this->once())
            ->method('compareManyToManyTable')
            ->with($manyToManyDefinition, ['old_table'])
            ->willReturn($mappingResult);

        $result = $this->coordinator->compareAll([$entity]);

        $results = iterator_to_array($result);
        $this->assertCount(3, $results);

        $operations = array_map(fn ($result) => $result->operation, $results);
        $this->assertContains(TableCompareResult::OPERATION_CREATE, $operations);
        $this->assertContains(TableCompareResult::OPERATION_DELETE, $operations);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllOrdersNewTablesByForeignKeyDependencies(): void
    {
        $customerEntity = $this->createTableEntity('customers');
        $addressEntity = $this->createTableEntity('customer_addresses');

        $customerResult = new TableCompareResult(
            'customers',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult(
                    'fk_customers_address_id',
                    TableCompareResult::OPERATION_CREATE,
                    'address_id',
                    'customer_addresses',
                ),
            ],
        );
        $addressResult = new TableCompareResult('customer_addresses', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->method('getTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);
        $this->entityTableComparator->method('compareEntityTable')
            ->willReturnCallback(fn (array $entityGroup, array $existingTables, string $tableName) => match ($tableName) {
                'customers' => $customerResult,
                'customer_addresses' => $addressResult,
            });

        $results = iterator_to_array($this->coordinator->compareAll([$customerEntity, $addressEntity]));

        $this->assertSame(['customer_addresses', 'customers'], array_map(fn ($result) => $result->name, $results));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllEmitsMappingTablesAfterParticipatingEntityTables(): void
    {
        $userEntity = $this->createTableEntity('users');
        $postEntity = $this->createTableEntity('posts');
        $manyToManyDefinition = [
            'tableName' => 'post_user',
            'ownerTable' => 'users',
            'targetTable' => 'posts',
            'ownerJoinColumn' => 'user_id',
            'targetJoinColumn' => 'post_id',
            'ownerReferencedColumn' => 'id',
            'targetReferencedColumn' => 'id',
            'extraProperties' => [],
            'primaryColumns' => ['user_id', 'post_id'],
        ];

        $userResult = new TableCompareResult('users', TableCompareResult::OPERATION_CREATE);
        $postResult = new TableCompareResult('posts', TableCompareResult::OPERATION_CREATE);
        $mappingResult = new TableCompareResult(
            'post_user',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult('fk_post_user_user_id', TableCompareResult::OPERATION_CREATE, 'user_id', 'users'),
                new ForeignKeyCompareResult('fk_post_user_post_id', TableCompareResult::OPERATION_CREATE, 'post_id', 'posts'),
            ],
        );

        $this->schemaReader->method('getTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn(['post_user' => $manyToManyDefinition]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);
        $this->entityTableComparator->method('compareEntityTable')
            ->willReturnCallback(fn (array $entityGroup, array $existingTables, string $tableName) => match ($tableName) {
                'users' => $userResult,
                'posts' => $postResult,
            });
        $this->mappingTableComparator->method('compareManyToManyTable')->willReturn($mappingResult);

        $results = iterator_to_array($this->coordinator->compareAll([$userEntity, $postEntity]));

        $this->assertSame(['users', 'posts', 'post_user'], array_map(fn ($result) => $result->name, $results));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllOrdersTableDeletesWithDependentsFirst(): void
    {
        $this->schemaReader->method('getTables')->willReturn(['customer_addresses', 'customers']);
        $this->schemaReader->method('getTableForeignKeys')
            ->willReturnCallback(fn (string $tableName) => match ($tableName) {
                'customers' => [
                    'fk_customers_address_id' => [
                        'column' => 'address_id',
                        'referencedTable' => 'customer_addresses',
                        'referencedColumn' => 'id',
                    ],
                ],
                default => [],
            });
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);

        $results = iterator_to_array($this->coordinator->compareAll([]));

        $this->assertSame(['customers', 'customer_addresses'], array_map(fn ($result) => $result->name, $results));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllFailsClearlyForCyclicForeignKeyDependencies(): void
    {
        $entityA = $this->createTableEntity('table_a');
        $entityB = $this->createTableEntity('table_b');
        $resultA = new TableCompareResult(
            'table_a',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult('fk_table_a_b_id', TableCompareResult::OPERATION_CREATE, 'b_id', 'table_b'),
            ],
        );
        $resultB = new TableCompareResult(
            'table_b',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult('fk_table_b_a_id', TableCompareResult::OPERATION_CREATE, 'a_id', 'table_a'),
            ],
        );

        $this->schemaReader->method('getTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);
        $this->entityTableComparator->method('compareEntityTable')
            ->willReturnCallback(fn (array $entityGroup, array $existingTables, string $tableName) => match ($tableName) {
                'table_a' => $resultA,
                'table_b' => $resultB,
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular table foreign key dependency detected while ordering schema changes');

        iterator_to_array($this->coordinator->compareAll([$entityA, $entityB]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllValidatesIndexesBeforeReadingSchema(): void
    {
        $entity = new ReflectionEntity(TestInvalidIndexEntity::class);

        $this->schemaReader->expects($this->never())
            ->method('getTables');

        $this->relationDefinitionCollector->expects($this->never())
            ->method('validateRelations');

        $this->entityTableComparator->expects($this->never())
            ->method('compareEntityTable');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index references unmapped property "missingField"');

        iterator_to_array($this->coordinator->compareAll([$entity]));
    }

    private function createTableEntity(string $tableName): ReflectionEntity
    {
        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(true);
        $entity->method('getTableName')->willReturn($tableName);

        return $entity;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompareAllSkipsNonEntitiesButStillProcessesLaterEntities(): void
    {
        $nonEntity = $this->createStub(ReflectionEntity::class);
        $nonEntity->method('isEntity')->willReturn(false);

        $entity = $this->createStub(ReflectionEntity::class);
        $entity->method('isEntity')->willReturn(true);
        $entity->method('getTableName')->willReturn('users');

        $createResult = new TableCompareResult('users', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->method('getTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);
        $this->entityTableComparator->expects($this->once())
            ->method('compareEntityTable')
            ->with([$entity], [], 'users')
            ->willReturn($createResult);

        $results = iterator_to_array($this->coordinator->compareAll([$nonEntity, $entity]));

        $this->assertCount(1, $results);
        $this->assertSame($createResult, $results[0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCircularDependencyExceptionMessageListsExactCycleAndRemediation(): void
    {
        $entityA = $this->createTableEntity('table_a');
        $entityB = $this->createTableEntity('table_b');
        $resultA = new TableCompareResult(
            'table_a',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult('fk_table_a_b_id', TableCompareResult::OPERATION_CREATE, 'b_id', 'table_b'),
            ],
        );
        $resultB = new TableCompareResult(
            'table_b',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult('fk_table_b_a_id', TableCompareResult::OPERATION_CREATE, 'a_id', 'table_a'),
            ],
        );

        $this->schemaReader->method('getTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);
        $this->entityTableComparator->method('compareEntityTable')
            ->willReturnCallback(fn (array $entityGroup, array $existingTables, string $tableName) => match ($tableName) {
                'table_a' => $resultA,
                'table_b' => $resultB,
            });

        try {
            iterator_to_array($this->coordinator->compareAll([$entityA, $entityB]));
            $this->fail('Expected a RuntimeException for the circular dependency.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Circular table foreign key dependency detected while ordering schema changes: table_a -> table_b -> table_a. '
                . 'Create one side of the relationship without an inline foreign key and add the constraint in a later migration.',
                $e->getMessage(),
            );
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetForeignKeyDependenciesSkipsNonCreateForeignKeysButKeepsScanning(): void
    {
        $dependentEntity = $this->createTableEntity('orders');
        $referencedEntity = $this->createTableEntity('customers');

        $orderResult = new TableCompareResult(
            'orders',
            TableCompareResult::OPERATION_CREATE,
            foreignKeys: [
                new ForeignKeyCompareResult('fk_orders_deleted_ref', TableCompareResult::OPERATION_DELETE, 'legacy_id', 'customers'),
                new ForeignKeyCompareResult('fk_orders_customer_id', TableCompareResult::OPERATION_CREATE, 'customer_id', 'customers'),
            ],
        );
        $customerResult = new TableCompareResult('customers', TableCompareResult::OPERATION_CREATE);

        $this->schemaReader->method('getTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectManyToManyTables')->willReturn([]);
        $this->relationDefinitionCollector->method('collectMorphToManyTables')->willReturn([]);
        $this->entityTableComparator->method('compareEntityTable')
            ->willReturnCallback(fn (array $entityGroup, array $existingTables, string $tableName) => match ($tableName) {
                'orders' => $orderResult,
                'customers' => $customerResult,
            });

        $results = iterator_to_array($this->coordinator->compareAll([$dependentEntity, $referencedEntity]));

        // customers must come before orders because of the surviving CREATE foreign key,
        // even though the DELETE foreign key is scanned first in the loop.
        $this->assertSame(['customers', 'orders'], array_map(fn ($result) => $result->name, $results));
    }
}

#[Index(['missingField'], name: 'idx_missing_field')]
#[Entity]
class TestInvalidIndexEntity {
    #[PrimaryKey]
    #[Property]
    public int $id;
}
