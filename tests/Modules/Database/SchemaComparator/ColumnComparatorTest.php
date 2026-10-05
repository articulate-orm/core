<?php

namespace Articulate\Tests\Modules\Database\SchemaComparator;

use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionProperty;
use Articulate\Attributes\Reflection\ReflectionRelation;
use Articulate\Modules\Database\SchemaComparator\Comparators\ColumnComparator;
use Articulate\Modules\Database\SchemaComparator\Models\ColumnCompareReport;
use Articulate\Modules\Database\SchemaComparator\Models\ColumnCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\CompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\PropertiesData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class ColumnComparatorTest extends TestCase {
    private ColumnComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new ColumnComparator();
    }

    // ===== Helpers =====

    private function scalarOf(string $entityClass, string $propertyName): ReflectionProperty
    {
        $entity = new ReflectionEntity($entityClass);
        foreach ($entity->getEntityProperties() as $prop) {
            if ($prop->getFieldName() === $propertyName) {
                return $prop;
            }
        }

        throw new \InvalidArgumentException("Property $propertyName not found in $entityClass");
    }

    private function relationOf(string $entityClass, string $propertyName): ReflectionRelation
    {
        $entity = new ReflectionEntity($entityClass);
        foreach ($entity->getColumnRelationProperties() as $rel) {
            if ($rel->getPropertyName() === $propertyName) {
                return $rel;
            }
        }

        throw new \InvalidArgumentException("Relation $propertyName not found in $entityClass");
    }

    // ===== Instance test =====

    public function testColumnComparatorCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ColumnComparator::class, $this->comparator);
    }

    // ===== compareColumns Tests =====

    public function testCompareColumnsWithEmptyInputs(): void
    {
        $report = $this->comparator->compareColumns([], []);

        $this->assertInstanceOf(ColumnCompareReport::class, $report);
        $this->assertEmpty($report->results);
        $this->assertEmpty($report->warnings);
    }

    public function testCompareColumnsCreateSingleColumn(): void
    {
        $propertiesIndexed = [
            'name' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [];
        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ColumnCompareResult::class, $results[0]);
        $this->assertEquals('name', $results[0]->name);
        $this->assertEquals(CompareResult::OPERATION_CREATE, $results[0]->operation);
        $this->assertEquals('string', $results[0]->propertyData->type);
        $this->assertFalse($results[0]->propertyData->isNullable);
        $this->assertNull($results[0]->propertyData->defaultValue);
        $this->assertEquals(255, $results[0]->propertyData->length);
        $this->assertInstanceOf(PropertiesData::class, $results[0]->columnData);
        $this->assertNull($results[0]->columnData->type);
    }

    public function testCompareColumnsCreateColumnWithAllProperties(): void
    {
        $propertiesIndexed = [
            'id' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => 'auto',
                'sequence' => null,
                'isPrimaryKey' => true,
                'isAutoIncrement' => true,
            ],
        ];

        $columnsIndexed = [];
        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals('id', $result->name);
        $this->assertEquals(CompareResult::OPERATION_CREATE, $result->operation);
        $this->assertEquals('int', $result->propertyData->type);
        $this->assertFalse($result->propertyData->isNullable);
        $this->assertNull($result->propertyData->defaultValue);
        $this->assertNull($result->propertyData->length);
        $this->assertEquals('auto', $result->propertyData->generatorType);
        $this->assertNull($result->propertyData->sequence);
        $this->assertTrue($result->propertyData->isPrimaryKey);
        $this->assertTrue($result->propertyData->isAutoIncrement);
    }

    public function testCompareColumnsDeleteSingleColumn(): void
    {
        $propertiesIndexed = [];

        $columnsIndexed = [
            'old_column' => (object) [
                'type' => 'varchar',
                'isNullable' => true,
                'defaultValue' => 'default_value',
                'length' => 100,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals('old_column', $result->name);
        $this->assertEquals(CompareResult::OPERATION_DELETE, $result->operation);
        $this->assertInstanceOf(PropertiesData::class, $result->propertyData);
        $this->assertNull($result->propertyData->type);
        $this->assertEquals('varchar', $result->columnData->type);
        $this->assertTrue($result->columnData->isNullable);
        $this->assertEquals('default_value', $result->columnData->defaultValue);
        $this->assertEquals(100, $result->columnData->length);
    }

    public function testCompareColumnsSkipsNotNullColumnWithoutDefault(): void
    {
        $propertiesIndexed = [];
        $columnsIndexed = [
            'secret' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed, 'users');

        $this->assertEmpty($report->results);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('"secret"', $report->warnings[0]);
        $this->assertStringContainsString('"users"', $report->warnings[0]);
    }

    public function testCompareColumnsSafeDeleteAlongsideSkippedColumn(): void
    {
        $propertiesIndexed = [];
        $columnsIndexed = [
            'secret' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
            'old_flag' => (object) [
                'type' => 'bool',
                'isNullable' => true,
                'defaultValue' => null,
                'length' => null,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed, 'users');

        $this->assertCount(1, $report->results);
        $this->assertEquals('old_flag', $report->results[0]->name);
        $this->assertEquals(CompareResult::OPERATION_DELETE, $report->results[0]->operation);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('"secret"', $report->warnings[0]);
    }

    public function testCompareColumnsUpdateColumnTypeOnly(): void
    {
        $propertiesIndexed = [
            'name' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'name' => (object) [
                'type' => 'int',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => null,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals('name', $result->name);
        $this->assertEquals(CompareResult::OPERATION_UPDATE, $result->operation);
        $this->assertFalse($result->typeMatch);
        $this->assertTrue($result->isNullableMatch);
        $this->assertTrue($result->isDefaultValueMatch);
        $this->assertFalse($result->isLengthMatch);
        $this->assertTrue($result->hasChanges());
    }

    public function testCompareColumnsUpdateColumnNullableOnly(): void
    {
        $propertiesIndexed = [
            'name' => [
                'type' => 'string',
                'nullable' => true,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'name' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals(CompareResult::OPERATION_UPDATE, $result->operation);
        $this->assertTrue($result->typeMatch);
        $this->assertFalse($result->isNullableMatch);
        $this->assertTrue($result->isDefaultValueMatch);
        $this->assertTrue($result->isLengthMatch);
        $this->assertTrue($result->hasChanges());
    }

    public function testCompareColumnsUpdateColumnDefaultValueOnly(): void
    {
        $propertiesIndexed = [
            'status' => [
                'type' => 'string',
                'nullable' => false,
                'default' => 'active',
                'length' => 50,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'status' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 50,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals(CompareResult::OPERATION_UPDATE, $result->operation);
        $this->assertTrue($result->typeMatch);
        $this->assertTrue($result->isNullableMatch);
        $this->assertFalse($result->isDefaultValueMatch);
        $this->assertTrue($result->isLengthMatch);
        $this->assertTrue($result->hasChanges());
    }

    public function testCompareColumnsUpdateColumnLengthOnly(): void
    {
        $propertiesIndexed = [
            'name' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 100,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'name' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals(CompareResult::OPERATION_UPDATE, $result->operation);
        $this->assertTrue($result->typeMatch);
        $this->assertTrue($result->isNullableMatch);
        $this->assertTrue($result->isDefaultValueMatch);
        $this->assertFalse($result->isLengthMatch);
        $this->assertTrue($result->hasChanges());
    }

    public function testCompareColumnsUpdateColumnPartialMatches(): void
    {
        $propertiesIndexed = [
            'mixed_column' => [
                'type' => 'string',
                'nullable' => true,
                'default' => 'new_default',
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'mixed_column' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals(CompareResult::OPERATION_UPDATE, $result->operation);

        $this->assertTrue($result->typeMatch);
        $this->assertFalse($result->isNullableMatch);
        $this->assertFalse($result->isDefaultValueMatch);
        $this->assertTrue($result->isLengthMatch);

        $this->assertTrue($result->hasChanges());

        $this->assertEquals('string', $result->propertyData->type);
        $this->assertTrue($result->propertyData->isNullable);
        $this->assertEquals('new_default', $result->propertyData->defaultValue);
        $this->assertEquals(255, $result->propertyData->length);

        $this->assertEquals('string', $result->columnData->type);
        $this->assertFalse($result->columnData->isNullable);
        $this->assertNull($result->columnData->defaultValue);
        $this->assertEquals(255, $result->columnData->length);
    }

    public function testCompareColumnsUpdatePropertiesDataConstructor(): void
    {
        $propertiesIndexed = [
            'test_column' => [
                'type' => 'varchar',
                'nullable' => false,
                'default' => 'test',
                'length' => 100,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => 'uuid',
                'sequence' => 'seq',
                'isPrimaryKey' => true,
                'isAutoIncrement' => true,
            ],
        ];

        $columnsIndexed = [
            'test_column' => (object) [
                'type' => 'text',
                'isNullable' => false,
                'defaultValue' => 'test',
                'length' => 100,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(1, $results);
        $result = $results[0];

        $this->assertEquals('varchar', $result->propertyData->type);
        $this->assertFalse($result->propertyData->isNullable);
        $this->assertEquals('test', $result->propertyData->defaultValue);
        $this->assertEquals(100, $result->propertyData->length);

        $this->assertEquals('text', $result->columnData->type);
        $this->assertFalse($result->columnData->isNullable);
        $this->assertEquals('test', $result->columnData->defaultValue);
        $this->assertEquals(100, $result->columnData->length);

        $this->assertFalse($result->typeMatch);
    }

    public function testCompareColumnsNoUpdateWhenIdentical(): void
    {
        $propertiesIndexed = [
            'name' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'name' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);

        $this->assertCount(0, $report->results);
    }

    public function testCompareColumnsDoesNotUpdateMysqlUnsignedIntPrimaryKey(): void
    {
        $propertiesIndexed = [
            'id' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => 'auto_increment',
                'sequence' => null,
                'isPrimaryKey' => true,
                'isAutoIncrement' => true,
            ],
        ];

        $columnsIndexed = [
            'id' => (object) [
                'type' => 'int unsigned',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => null,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);

        $this->assertCount(0, $report->results);
    }

    public function testCompareColumnsDoesNotUpdateMysqlUnsignedIntForeignKey(): void
    {
        $propertiesIndexed = [
            'product_id' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => true,
                'referencedColumn' => 'id',
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'product_id' => (object) [
                'type' => 'int unsigned',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => null,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);

        $this->assertCount(0, $report->results);
    }

    public function testCompareColumnsStillUpdatesNonKeyMysqlUnsignedInt(): void
    {
        $propertiesIndexed = [
            'quantity' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'quantity' => (object) [
                'type' => 'int unsigned',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => null,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);

        $this->assertCount(1, $report->results);
        $this->assertSame('quantity', $report->results[0]->name);
        $this->assertSame(CompareResult::OPERATION_UPDATE, $report->results[0]->operation);
        $this->assertFalse($report->results[0]->typeMatch);
    }

    public function testCompareColumnsMixedOperations(): void
    {
        $propertiesIndexed = [
            'new_column' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
            'changed_column' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 100,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $columnsIndexed = [
            'changed_column' => (object) [
                'type' => 'string',
                'isNullable' => false,
                'defaultValue' => null,
                'length' => 255,
            ],
            'deleted_column' => (object) [
                'type' => 'bool',
                'isNullable' => true,
                'defaultValue' => 'false',
                'length' => null,
            ],
        ];

        $report = $this->comparator->compareColumns($propertiesIndexed, $columnsIndexed);
        $results = $report->results;

        $this->assertCount(3, $results);

        $operations = array_map(fn ($result) => $result->operation, $results);
        $names = array_map(fn ($result) => $result->name, $results);

        $this->assertContains(CompareResult::OPERATION_CREATE, $operations);
        $this->assertContains(CompareResult::OPERATION_UPDATE, $operations);
        $this->assertContains(CompareResult::OPERATION_DELETE, $operations);

        $this->assertContains('new_column', $names);
        $this->assertContains('changed_column', $names);
        $this->assertContains('deleted_column', $names);
    }

    // ===== mergeColumnDefinition Tests =====

    public function testMergeColumnDefinitionWithNewColumn(): void
    {
        $property = $this->scalarOf(CcStringName255::class, 'name');

        $result = $this->comparator->mergeColumnDefinition([], 'name', $property, 'users');

        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('string', $result['name']['type']);
        $this->assertFalse($result['name']['nullable']);
        $this->assertNull($result['name']['default']);
        $this->assertEquals(255, $result['name']['length']);
        $this->assertNull($result['name']['relation']);
        $this->assertFalse($result['name']['foreignKeyRequired']);
        $this->assertNull($result['name']['referencedColumn']);
    }

    public function testMergeColumnDefinitionWithRelationProperty(): void
    {
        $property = $this->relationOf(CcPostA::class, 'user');

        $result = $this->comparator->mergeColumnDefinition([], 'user_id', $property, 'posts');

        $this->assertArrayHasKey('user_id', $result);
        $this->assertEquals('int', $result['user_id']['type']);
        $this->assertEquals($property, $result['user_id']['relation']);
        $this->assertTrue($result['user_id']['foreignKeyRequired']);
        $this->assertEquals('id', $result['user_id']['referencedColumn']);
    }

    public function testMergeColumnDefinitionWithExistingColumnCompatible(): void
    {
        $existing = $this->scalarOf(CcStringName255::class, 'name');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'name', $existing, 'users');

        $incoming = $this->scalarOf(CcNullableName::class, 'name');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'name', $incoming, 'users');

        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('string', $result['name']['type']);
        $this->assertTrue($result['name']['nullable']);
        $this->assertEquals(255, $result['name']['length']);
    }

    public function testMergeColumnDefinitionThrowsOnTypeConflict(): void
    {
        $existing = $this->scalarOf(CcStringName255::class, 'name');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'name', $existing, 'users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Column "name" on table "users" conflicts between entities');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'name', $this->scalarOf(CcIntName::class, 'name'), 'users');
    }

    public function testMergeColumnDefinitionThrowsOnLengthConflict(): void
    {
        $existing = $this->scalarOf(CcStringName255::class, 'name');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'name', $existing, 'users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Column "name" on table "users" conflicts between entities');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'name', $this->scalarOf(CcStringName100::class, 'name'), 'users');
    }

    public function testMergeColumnDefinitionThrowsOnDefaultValueConflict(): void
    {
        $existing = $this->scalarOf(CcStatusDefault1::class, 'status');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'status', $existing, 'users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Column "status" on table "users" conflicts between entities');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'status', $this->scalarOf(CcStatusDefault2::class, 'status'), 'users');
    }

    public function testMergeColumnDefinitionThrowsOnRelationVsScalarConflict(): void
    {
        $scalar = $this->scalarOf(CcScalarUserId::class, 'userId');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $scalar, 'posts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid duplicate mapping for column "user_id" on table "posts"');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $this->relationOf(CcPostA::class, 'user'), 'posts');
    }

    public function testMergeColumnDefinitionThrowsOnRelationFirstScalarSecond(): void
    {
        $relation = $this->relationOf(CcOrder::class, 'customer');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'customer_id', $relation, 'orders');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid duplicate mapping for column "customer_id" on table "orders"');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'customer_id', $this->scalarOf(CcScalarCustomerId::class, 'customerId'), 'orders');
    }

    public function testMergeColumnDefinitionScalarRelationConflictMessageIncludesAllDetails(): void
    {
        $scalar = $this->scalarOf(CcScalarCustomerId::class, 'customerId');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'customer_id', $scalar, 'orders');

        $relation = $this->relationOf(CcOrder::class, 'customer');

        try {
            $this->comparator->mergeColumnDefinition($propertiesIndexed, 'customer_id', $relation, 'orders');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('customer_id', $message);
            $this->assertStringContainsString('orders', $message);
            $this->assertStringContainsString(CcScalarCustomerId::class . '::$customerId', $message);
            $this->assertStringContainsString(CcOrder::class . '::$customer', $message);
            $this->assertStringContainsString(CcCustomer::class, $message);
            $this->assertStringContainsString('#[Property]', $message);
            $this->assertStringContainsString('Remove the scalar property', $message);
        }
    }

    public function testMergeColumnDefinitionThrowsOnRelationTargetConflict(): void
    {
        $existing = $this->relationOf(CcPostA::class, 'user');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $existing, 'posts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Relation column "user_id" on table "posts" points to different targets');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $this->relationOf(CcConflictPost::class, 'user'), 'posts');
    }

    public function testMergeColumnDefinitionWithCompatibleRelationsSameTarget(): void
    {
        $existing = $this->relationOf(CcPostA::class, 'user');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $existing, 'posts');

        $compatible = $this->relationOf(CcPostB::class, 'user');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $compatible, 'posts');

        $this->assertArrayHasKey('user_id', $result);
        $this->assertEquals('int', $result['user_id']['type']);
        $this->assertTrue($result['user_id']['foreignKeyRequired']);
        $this->assertEquals('id', $result['user_id']['referencedColumn']);
    }

    public function testMergeColumnDefinitionWithNullTargetRelations(): void
    {
        $existing = $this->relationOf(CcCommentA::class, 'commentable');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'commentable_id', $existing, 'comments');

        $incoming = $this->relationOf(CcCommentB::class, 'commentable');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'commentable_id', $incoming, 'comments');

        $this->assertArrayHasKey('commentable_id', $result);
        $this->assertEquals('int', $result['commentable_id']['type']);
    }

    public function testMergeColumnDefinitionWithMorphToRelationsNullTargets(): void
    {
        $existing = $this->relationOf(CcCommentA::class, 'commentable');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'commentable_id', $existing, 'comments');

        $another = $this->relationOf(CcCommentB::class, 'commentable');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'commentable_id', $another, 'comments');

        $this->assertArrayHasKey('commentable_id', $result);
        $this->assertEquals('int', $result['commentable_id']['type']);
    }

    public function testMergeColumnDefinitionMergesAllPropertiesWithNullCoalescing(): void
    {
        $existing = $this->scalarOf(CcPkUuidTestColumn::class, 'testColumn');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'test_column', $existing, 'test_table');

        $incoming = $this->scalarOf(CcPlainTestColumn::class, 'testColumn');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'test_column', $incoming, 'test_table');

        $this->assertArrayHasKey('test_column', $result);
        $this->assertEquals('uuid_v4', $result['test_column']['generatorType']);
        $this->assertEquals('my_sequence', $result['test_column']['sequence']);
        $this->assertTrue($result['test_column']['isPrimaryKey']);
        $this->assertFalse($result['test_column']['isAutoIncrement']);
    }

    public function testMergeColumnDefinitionMergesRelationProperties(): void
    {
        $existing = $this->relationOf(CcPostA::class, 'user');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $existing, 'posts');

        $incoming = $this->relationOf(CcPostB::class, 'user');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $incoming, 'posts');

        $this->assertArrayHasKey('user_id', $result);
        $this->assertTrue($result['user_id']['foreignKeyRequired']);
        $this->assertEquals('id', $result['user_id']['referencedColumn']);
    }

    // ===== addMorphToColumns Tests =====

    public function testAddMorphToColumnsCreatesBothColumns(): void
    {
        $relation = $this->relationOf(CcCommentA::class, 'commentable');
        $result = $this->comparator->addMorphToColumns([], $relation, 'comments');

        $this->assertArrayHasKey('commentable_type', $result);
        $this->assertArrayHasKey('commentable_id', $result);

        $typeColumn = $result['commentable_type'];
        $this->assertEquals('string', $typeColumn['type']);
        $this->assertFalse($typeColumn['nullable']);
        $this->assertNull($typeColumn['default']);
        $this->assertEquals(255, $typeColumn['length']);
        $this->assertNull($typeColumn['relation']);
        $this->assertFalse($typeColumn['foreignKeyRequired']);
        $this->assertNull($typeColumn['referencedColumn']);

        $idColumn = $result['commentable_id'];
        $this->assertEquals('int', $idColumn['type']);
        $this->assertFalse($idColumn['nullable']);
        $this->assertNull($idColumn['default']);
        $this->assertNull($idColumn['length']);
        $this->assertEquals($relation, $idColumn['relation']);
        $this->assertFalse($idColumn['foreignKeyRequired']);
        $this->assertEquals('id', $idColumn['referencedColumn']);
    }

    public function testAddMorphToColumnsMergesWithExistingCompatibleTypeColumn(): void
    {
        $relation = $this->relationOf(CcTaggable::class, 'taggable');

        $propertiesIndexed = [
            'taggable_type' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $result = $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'tags');

        $this->assertArrayHasKey('taggable_type', $result);
        $this->assertArrayHasKey('taggable_id', $result);

        $typeColumn = $result['taggable_type'];
        $this->assertEquals('string', $typeColumn['type']);
        $this->assertFalse($typeColumn['nullable']);
        $this->assertEquals(255, $typeColumn['length']);
    }

    public function testAddMorphToColumnsThrowsOnTypeColumnConflict(): void
    {
        $relation = $this->relationOf(CcTaggable::class, 'taggable');

        $propertiesIndexed = [
            'taggable_type' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Morph type column "taggable_type" conflicts on table "tags"');

        $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'tags');
    }

    public function testAddMorphToColumnsThrowsOnIdColumnConflict(): void
    {
        $relation = $this->relationOf(CcTaggable::class, 'taggable');

        $propertiesIndexed = [
            'taggable_id' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Morph ID column "taggable_id" conflicts on table "tags"');

        $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'tags');
    }

    public function testAddMorphToColumnsMergesNullableIdColumn(): void
    {
        $relation = $this->relationOf(CcTaggable::class, 'taggable');

        $propertiesIndexed = [
            'taggable_id' => [
                'type' => 'int',
                'nullable' => true,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $result = $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'tags');

        $idColumn = $result['taggable_id'];
        $this->assertEquals('int', $idColumn['type']);
        $this->assertFalse($idColumn['nullable']);
        $this->assertEquals($relation, $idColumn['relation']);
    }

    public function testAddMorphToColumnsMergesIdColumnWithAllExistingProperties(): void
    {
        $relation = $this->relationOf(CcCommentA::class, 'commentable');

        $propertiesIndexed = [
            'commentable_id' => [
                'type' => 'int',
                'nullable' => true,
                'default' => '0',
                'length' => 11,
                'relation' => null,
                'foreignKeyRequired' => true,
                'referencedColumn' => 'uuid',
                'generatorType' => 'auto',
                'sequence' => 'comment_seq',
                'isPrimaryKey' => true,
                'isAutoIncrement' => true,
            ],
        ];

        $result = $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'comments');

        $idColumn = $result['commentable_id'];
        $this->assertEquals('int', $idColumn['type']);
        $this->assertFalse($idColumn['nullable']);
        $this->assertEquals('0', $idColumn['default']);
        $this->assertEquals(11, $idColumn['length']);
        $this->assertEquals($relation, $idColumn['relation']);
        $this->assertTrue($idColumn['foreignKeyRequired']);
        $this->assertEquals('id', $idColumn['referencedColumn']);
        $this->assertEquals('auto', $idColumn['generatorType']);
        $this->assertEquals('comment_seq', $idColumn['sequence']);
        $this->assertTrue($idColumn['isPrimaryKey']);
        $this->assertTrue($idColumn['isAutoIncrement']);
    }

    public function testAddMorphToColumnsMergesIdColumnWithNullExistingProperties(): void
    {
        $relation = $this->relationOf(CcCommentA::class, 'commentable');

        $propertiesIndexed = [
            'commentable_id' => [
                'type' => 'int',
                'nullable' => false,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $result = $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'comments');

        $idColumn = $result['commentable_id'];
        $this->assertEquals('int', $idColumn['type']);
        $this->assertFalse($idColumn['nullable']);
        $this->assertNull($idColumn['default']);
        $this->assertNull($idColumn['length']);
        $this->assertEquals($relation, $idColumn['relation']);
        $this->assertFalse($idColumn['foreignKeyRequired']);
        $this->assertEquals('id', $idColumn['referencedColumn']);
        $this->assertNull($idColumn['generatorType']);
        $this->assertNull($idColumn['sequence']);
        $this->assertFalse($idColumn['isPrimaryKey']);
        $this->assertFalse($idColumn['isAutoIncrement']);
    }

    // ===== normalizeTypeName Tests =====

    public function testNormalizeTypeNameWithNull(): void
    {
        $reflection = new ReflectionClass($this->comparator);
        $method = $reflection->getMethod('normalizeTypeName');
        $method->setAccessible(true);

        $result = $method->invoke($this->comparator, null);
        $this->assertNull($result);
    }

    public function testNormalizeTypeNameWithString(): void
    {
        $reflection = new ReflectionClass($this->comparator);
        $method = $reflection->getMethod('normalizeTypeName');
        $method->setAccessible(true);

        $result = $method->invoke($this->comparator, 'string');
        $this->assertEquals('string', $result);

        $result = $method->invoke($this->comparator, '?int');
        $this->assertEquals('int', $result);

        $result = $method->invoke($this->comparator, 'string|null');
        $this->assertEquals('string', $result);
    }

    public function testNormalizeTypeNameWithReflectionNamedType(): void
    {
        $reflection = new ReflectionClass($this->comparator);
        $method = $reflection->getMethod('normalizeTypeName');
        $method->setAccessible(true);

        $namedType = $this->createStub(\ReflectionNamedType::class);
        $namedType->method('getName')->willReturn('DateTime');

        $result = $method->invoke($this->comparator, $namedType);
        $this->assertEquals('DateTime', $result);
    }

    public function testNormalizeTypeNameWithReflectionUnionType(): void
    {
        $reflection = new ReflectionClass($this->comparator);
        $method = $reflection->getMethod('normalizeTypeName');
        $method->setAccessible(true);

        $stringType = $this->createStub(\ReflectionNamedType::class);
        $stringType->method('getName')->willReturn('string');

        $nullType = $this->createStub(\ReflectionNamedType::class);
        $nullType->method('getName')->willReturn('null');

        $unionType = $this->createStub(\ReflectionUnionType::class);
        $unionType->method('getTypes')->willReturn([$stringType, $nullType]);

        $result = $method->invoke($this->comparator, $unionType);
        $this->assertEquals('string', $result);
    }

    public function testNormalizeTypeNameWithReflectionUnionTypeAllNull(): void
    {
        $reflection = new ReflectionClass($this->comparator);
        $method = $reflection->getMethod('normalizeTypeName');
        $method->setAccessible(true);

        $nullType1 = $this->createStub(\ReflectionNamedType::class);
        $nullType1->method('getName')->willReturn('null');

        $nullType2 = $this->createStub(\ReflectionNamedType::class);
        $nullType2->method('getName')->willReturn('null');

        $unionType = $this->createStub(\ReflectionUnionType::class);
        $unionType->method('getTypes')->willReturn([$nullType1, $nullType2]);

        $result = $method->invoke($this->comparator, $unionType);
        $this->assertNull($result);
    }

    public function testNormalizeTypeNameWithOtherType(): void
    {
        $reflection = new ReflectionClass($this->comparator);
        $method = $reflection->getMethod('normalizeTypeName');
        $method->setAccessible(true);

        $result = $method->invoke($this->comparator, 123);
        $this->assertEquals('123', $result);
    }

    // ===== Additional coverage tests =====

    public function testMergeColumnDefinitionThrowsWhenRelationConflictsWithScalar(): void
    {
        $scalar = $this->scalarOf(CcScalarUserId::class, 'userId');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $scalar, 'posts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid duplicate mapping for column "user_id" on table "posts"');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $this->relationOf(CcPostA::class, 'user'), 'posts');
    }

    public function testMergeColumnDefinitionThrowsWhenRelationsPointToDifferentTargets(): void
    {
        $existing = $this->relationOf(CcPostA::class, 'user');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $existing, 'posts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Relation column "user_id" on table "posts" points to different targets');

        $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $this->relationOf(CcConflictPost::class, 'user'), 'posts');
    }

    public function testAddMorphToColumnsMergesExistingCompatibleTypeColumn(): void
    {
        $relation = $this->relationOf(CcTaggable::class, 'taggable');

        $propertiesIndexed = [
            'taggable_type' => [
                'type' => 'string',
                'nullable' => false,
                'default' => null,
                'length' => 255,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $result = $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'tags');

        $this->assertArrayHasKey('taggable_type', $result);
        $typeColumn = $result['taggable_type'];
        $this->assertEquals('string', $typeColumn['type']);
        $this->assertEquals(255, $typeColumn['length']);
    }

    public function testAddMorphToColumnsMergesExistingCompatibleIdColumn(): void
    {
        $relation = $this->relationOf(CcCommentA::class, 'commentable');

        $propertiesIndexed = [
            'commentable_id' => [
                'type' => 'int',
                'nullable' => true,
                'default' => null,
                'length' => null,
                'relation' => null,
                'foreignKeyRequired' => false,
                'referencedColumn' => null,
                'generatorType' => null,
                'sequence' => null,
                'isPrimaryKey' => false,
                'isAutoIncrement' => false,
            ],
        ];

        $result = $this->comparator->addMorphToColumns($propertiesIndexed, $relation, 'comments');

        $this->assertArrayHasKey('commentable_id', $result);
        $idColumn = $result['commentable_id'];
        $this->assertEquals('int', $idColumn['type']);
        $this->assertFalse($idColumn['nullable']);
        $this->assertEquals($relation, $idColumn['relation']);
    }

    public function testMergeColumnDefinitionCoversAllNullCoalescing(): void
    {
        $existing = $this->relationOf(CcPostA::class, 'user');
        $propertiesIndexed = $this->comparator->mergeColumnDefinition([], 'user_id', $existing, 'posts');

        $incoming = $this->relationOf(CcPostB::class, 'user');
        $result = $this->comparator->mergeColumnDefinition($propertiesIndexed, 'user_id', $incoming, 'posts');

        $this->assertTrue($result['user_id']['foreignKeyRequired']);
        $this->assertEquals('id', $result['user_id']['referencedColumn']);
        $this->assertEquals($existing, $result['user_id']['relation']);
    }

    // ── mergeColumnProperties mutation killers ────────────────────────────────

    /**
     * Call the private mergeColumnProperties via reflection.
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function mergeProps(array $incoming, array $existing): array
    {
        $m = new \ReflectionMethod(ColumnComparator::class, 'mergeColumnProperties');
        $m->setAccessible(true);

        return $m->invoke($this->comparator, $incoming, $existing);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function makeProps(array $overrides = []): array
    {
        return array_merge([
            'type' => 'string',
            'nullable' => false,
            'default' => null,
            'length' => 255,
            'relation' => null,
            'foreignKeyRequired' => false,
            'referencedColumn' => null,
            'generatorType' => null,
            'sequence' => null,
            'isPrimaryKey' => false,
            'isAutoIncrement' => false,
            'propertyName' => 'name',
            'declaringClass' => 'SomeClass',
        ], $overrides);
    }

    /**
     * Line 256: `'nullable' => $existing['nullable'] || $incoming['nullable']`
     * Mutation: `!$existing['nullable'] || $incoming['nullable']` — flips existing.
     * When existing=true and incoming=false the result must still be true.
     */
    public function testMergeColumnPropertiesNullableUsesOrLogic(): void
    {
        $existing = $this->makeProps(['nullable' => true]);
        $incoming = $this->makeProps(['nullable' => false]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['nullable'], 'nullable must be true when existing is true (|| not !existing||incoming)');
    }

    /**
     * Also verify the symmetric case: incoming=true wins even when existing=false.
     */
    public function testMergeColumnPropertiesNullableIncomingTrueWins(): void
    {
        $existing = $this->makeProps(['nullable' => false]);
        $incoming = $this->makeProps(['nullable' => true]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['nullable']);
    }

    /**
     * Line 260: `'foreignKeyRequired' => $existing['foreignKeyRequired'] || $incoming['foreignKeyRequired']`
     * Mutation: `&&` — with && the result would be false when only one side is true.
     */
    public function testMergeColumnPropertiesForeignKeyRequiredUsesOrLogic(): void
    {
        $existing = $this->makeProps(['foreignKeyRequired' => true]);
        $incoming = $this->makeProps(['foreignKeyRequired' => false]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['foreignKeyRequired'], 'foreignKeyRequired must be true when existing is true');
    }

    public function testMergeColumnPropertiesForeignKeyRequiredIncomingTrueWins(): void
    {
        $existing = $this->makeProps(['foreignKeyRequired' => false]);
        $incoming = $this->makeProps(['foreignKeyRequired' => true]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['foreignKeyRequired']);
    }

    /**
     * Line 264: `'isPrimaryKey' => $existing['isPrimaryKey'] || $incoming['isPrimaryKey']`
     * Various negation mutations may flip this.
     */
    public function testMergeColumnPropertiesIsPrimaryKeyUsesOrLogic(): void
    {
        $existing = $this->makeProps(['isPrimaryKey' => true]);
        $incoming = $this->makeProps(['isPrimaryKey' => false]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['isPrimaryKey'], 'isPrimaryKey must be true when existing is true');
    }

    public function testMergeColumnPropertiesIsPrimaryKeyIncomingTrueWins(): void
    {
        $existing = $this->makeProps(['isPrimaryKey' => false]);
        $incoming = $this->makeProps(['isPrimaryKey' => true]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['isPrimaryKey']);
    }

    /**
     * Line 265: `'isAutoIncrement' => $existing['isAutoIncrement'] || $incoming['isAutoIncrement']`
     * Mutation: `&&`.
     */
    public function testMergeColumnPropertiesIsAutoIncrementUsesOrLogic(): void
    {
        $existing = $this->makeProps(['isAutoIncrement' => true]);
        $incoming = $this->makeProps(['isAutoIncrement' => false]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['isAutoIncrement'], 'isAutoIncrement must be true when existing is true');
    }

    public function testMergeColumnPropertiesIsAutoIncrementIncomingTrueWins(): void
    {
        $existing = $this->makeProps(['isAutoIncrement' => false]);
        $incoming = $this->makeProps(['isAutoIncrement' => true]);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertTrue($result['isAutoIncrement']);
    }

    /**
     * Line 261: `'referencedColumn' => $existing['referencedColumn'] ?? $incoming['referencedColumn']`
     * Mutation: swaps operands to `$incoming['referencedColumn'] ?? $existing['referencedColumn']`.
     * When existing is non-null it must win, even if incoming is also non-null and different.
     */
    public function testMergeColumnPropertiesReferencedColumnPrefersExistingWhenBothSet(): void
    {
        $existing = $this->makeProps(['referencedColumn' => 'existing_id']);
        $incoming = $this->makeProps(['referencedColumn' => 'incoming_id']);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertSame('existing_id', $result['referencedColumn']);
    }

    public function testMergeColumnPropertiesReferencedColumnFallsBackToIncomingWhenExistingNull(): void
    {
        $existing = $this->makeProps(['referencedColumn' => null]);
        $incoming = $this->makeProps(['referencedColumn' => 'incoming_id']);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertSame('incoming_id', $result['referencedColumn']);
    }

    /**
     * Line 262: `'generatorType' => $existing['generatorType'] ?? $incoming['generatorType']`
     */
    public function testMergeColumnPropertiesGeneratorTypePrefersExistingWhenBothSet(): void
    {
        $existing = $this->makeProps(['generatorType' => 'existing_gen']);
        $incoming = $this->makeProps(['generatorType' => 'incoming_gen']);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertSame('existing_gen', $result['generatorType']);
    }

    public function testMergeColumnPropertiesGeneratorTypeFallsBackToIncomingWhenExistingNull(): void
    {
        $existing = $this->makeProps(['generatorType' => null]);
        $incoming = $this->makeProps(['generatorType' => 'incoming_gen']);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertSame('incoming_gen', $result['generatorType']);
    }

    /**
     * Line 263: `'sequence' => $existing['sequence'] ?? $incoming['sequence']`
     */
    public function testMergeColumnPropertiesSequencePrefersExistingWhenBothSet(): void
    {
        $existing = $this->makeProps(['sequence' => 'existing_seq']);
        $incoming = $this->makeProps(['sequence' => 'incoming_seq']);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertSame('existing_seq', $result['sequence']);
    }

    public function testMergeColumnPropertiesSequenceFallsBackToIncomingWhenExistingNull(): void
    {
        $existing = $this->makeProps(['sequence' => null]);
        $incoming = $this->makeProps(['sequence' => 'incoming_seq']);

        $result = $this->mergeProps($incoming, $existing);

        $this->assertSame('incoming_seq', $result['sequence']);
    }

    /**
     * validateRelationConflicts, line 228: `if ($incoming['relation'] && $existing['relation'])`
     * Mutation to `||` would enter the branch when only one side has a relation,
     * where it would then crash dereferencing a null relation. Covering the
     * "incoming has relation, existing does not" case (handled earlier by the
     * scalar-vs-relation branch) isn't useful here; instead assert that two
     * compatible relations (both pointing to the same target/column) merge
     * without throwing, proving the && branch runs correctly when both sides
     * truly have relations.
     */
    public function testMergeColumnDefinitionWithTwoCompatibleRelationsDoesNotThrow(): void
    {
        $propertiesIndexed = $this->comparator->mergeColumnDefinition(
            [],
            'user_id',
            $this->relationOf(CcPostA::class, 'user'),
            'posts'
        );

        $result = $this->comparator->mergeColumnDefinition(
            $propertiesIndexed,
            'user_id',
            $this->relationOf(CcPostB::class, 'user'),
            'posts'
        );

        $this->assertSame('int', $result['user_id']['type']);
    }
}

// Fixture entity classes — defined in same file to avoid PSR-4 one-class-per-file constraint

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\MorphTo;

// Relation targets
#[Entity] class CcUser {
    #[PrimaryKey]
    public int $id;
}
#[Entity] class CcCustomer {
    #[PrimaryKey]
    public int $id;
}

// Scalar fixtures
#[Entity] class CcStringName255 {
    #[Property(maxLength: 255)]
    public string $name;
}
#[Entity] class CcIntName {
    #[Property]
    public int $name;
}
#[Entity] class CcStringName100 {
    #[Property(maxLength: 100)]
    public string $name;
}
#[Entity] class CcNullableName {
    #[Property(maxLength: 255)]
    public ?string $name;
}
#[Entity] class CcStatusDefault1 {
    #[Property(defaultValue: 'default1', maxLength: 255)]
    public string $status;
}
#[Entity] class CcStatusDefault2 {
    #[Property(defaultValue: 'default2', maxLength: 255)]
    public string $status;
}
#[Entity] class CcScalarUserId {
    #[Property]
    public int $userId;
}
#[Entity] class CcScalarCustomerId {
    #[Property]
    public int $customerId;
}
#[Entity] class CcPkUuidTestColumn {
    #[PrimaryKey(generator: PrimaryKey::GENERATOR_UUID_V4, sequence: 'my_sequence')]
    public string $testColumn;
}
#[Entity] class CcPlainTestColumn {
    #[Property]
    public string $testColumn;
}

// Relation fixtures
#[Entity] class CcPostA {
    #[ManyToOne(targetEntity: CcUser::class)]
    public CcUser $user;
}
#[Entity] class CcPostB {
    #[ManyToOne(targetEntity: CcUser::class)]
    public CcUser $user;
}
#[Entity] class CcConflictPost {
    #[ManyToOne(targetEntity: CcCustomer::class)]
    public CcCustomer $user;
}
#[Entity] class CcOrder {
    #[ManyToOne(targetEntity: CcCustomer::class)]
    public CcCustomer $customer;
}

// Morph fixtures
#[Entity] class CcCommentA {
    #[MorphTo]
    public $commentable;
}
#[Entity] class CcCommentB {
    #[MorphTo]
    public $commentable;
}
#[Entity] class CcTaggable {
    #[MorphTo]
    public $taggable;
}
