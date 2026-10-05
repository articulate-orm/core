<?php

namespace Articulate\Tests\Modules\Migrations;

use Articulate\Modules\Database\SchemaComparator\Models\CompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\ForeignKeyCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\IndexCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\PropertiesData;
use Articulate\Modules\Database\SchemaComparator\Models\TableCompareResult;
use Articulate\Modules\Migrations\Generator\AbstractMigrationGenerator;
use Articulate\Tests\AbstractTestCase;
use Articulate\Utils\TypeRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class AbstractMigrationGeneratorTest extends AbstractTestCase {
    private TestMigrationGenerator $generator;

    private TypeRegistry $typeRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->typeRegistry = $this->createMock(TypeRegistry::class);
        $this->generator = new TestMigrationGenerator($this->typeRegistry);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsDropTableForDeleteOperation(): void
    {
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_DELETE,
            [],
            [],
            [],
            []
        );

        $result = $this->generator->generate($compareResult);

        $this->assertEquals(['DROP TABLE test_table'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsCreateTableForCreateOperation(): void
    {
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_CREATE,
            [],
            [],
            [],
            []
        );

        $result = $this->generator->generate($compareResult);

        $this->assertEquals(['CREATE TABLE test_table'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateReturnsAlterTableForUpdateOperation(): void
    {
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_UPDATE,
            [],
            [],
            [],
            []
        );

        $result = $this->generator->generate($compareResult);

        $this->assertEquals(['ALTER TABLE test_table'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRollbackReturnsDropTableForCreateOperation(): void
    {
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_CREATE,
            [],
            [],
            [],
            []
        );

        $result = $this->generator->rollback($compareResult);

        $this->assertEquals(['DROP TABLE test_table'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRollbackReturnsCreateTableForDeleteOperation(): void
    {
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_DELETE,
            [],
            [],
            [],
            []
        );

        $result = $this->generator->rollback($compareResult);

        $this->assertEquals(['CREATE TABLE test_table FROM ROLLBACK'], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRollbackReturnsAlterTableForUpdateOperation(): void
    {
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_UPDATE,
            [],
            [],
            [],
            []
        );

        $result = $this->generator->rollback($compareResult);

        $this->assertEquals(['ALTER TABLE test_table ROLLBACK'], $result);
    }

    public function testColumnDefinitionGeneratesBasicColumn(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            defaultValue: null,
            isPrimaryKey: false,
            isAutoIncrement: false
        );

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('string')
            ->willReturn('VARCHAR(255)');

        $result = $this->generator->testColumnDefinition('name', $column);

        $this->assertEquals('"name" VARCHAR(255) NOT NULL', $result);
    }

    public function testColumnDefinitionHandlesNullableColumn(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: true,
            defaultValue: null,
            isPrimaryKey: false,
            isAutoIncrement: false
        );

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('string')
            ->willReturn('VARCHAR(255)');

        $result = $this->generator->testColumnDefinition('email', $column);

        $this->assertEquals('"email" VARCHAR(255)', $result);
    }

    public function testColumnDefinitionHandlesDefaultValue(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            defaultValue: 'default_value',
            isPrimaryKey: false,
            isAutoIncrement: false
        );

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('string')
            ->willReturn('VARCHAR(255)');

        $result = $this->generator->testColumnDefinition('status', $column);

        $this->assertEquals('"status" VARCHAR(255) NOT NULL DEFAULT \'default_value\'', $result);
    }

    public function testColumnDefinitionHandlesAutoIncrement(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: false,
            defaultValue: null,
            isPrimaryKey: false,
            isAutoIncrement: true
        );

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('int')
            ->willReturn('INT');

        $result = $this->generator->testColumnDefinition('id', $column);

        $this->assertEquals('"id" INT AUTO_INCREMENT NOT NULL', $result);
    }

    public function testColumnDefinitionHandlesPrimaryKeyGeneration(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: false,
            defaultValue: null,
            generatorType: 'identity',
            sequence: 'test_seq',
            isPrimaryKey: true,
            isAutoIncrement: false
        );

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('int')
            ->willReturn('INT');

        $result = $this->generator->testColumnDefinition('id', $column);

        $this->assertEquals('"id" INT GENERATED BY IDENTITY NOT NULL', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testForeignKeyDefinitionGeneratesBasicForeignKey(): void
    {
        $foreignKey = new ForeignKeyCompareResult(
            'fk_user_id',
            CompareResult::OPERATION_CREATE,
            'user_id',
            'users',
            'id'
        );

        $result = $this->generator->testForeignKeyDefinition($foreignKey, true);

        $this->assertEquals('ADD CONSTRAINT "fk_user_id" FOREIGN KEY ("user_id") REFERENCES "users"("id")', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testForeignKeyDefinitionWithoutAddPrefix(): void
    {
        $foreignKey = new ForeignKeyCompareResult(
            'fk_user_id',
            CompareResult::OPERATION_CREATE,
            'user_id',
            'users',
            'id'
        );

        $result = $this->generator->testForeignKeyDefinition($foreignKey, false);

        $this->assertEquals('CONSTRAINT "fk_user_id" FOREIGN KEY ("user_id") REFERENCES "users"("id")', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateIndexSqlCreatesRegularIndex(): void
    {
        $index = new IndexCompareResult(
            'idx_name',
            CompareResult::OPERATION_CREATE,
            ['first_name', 'last_name'],
            false,
            false
        );

        $result = $this->generator->testGenerateIndexSql($index, 'users', true);

        $this->assertEquals('ADD INDEX "idx_name" ("first_name", "last_name")', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateIndexSqlCreatesUniqueIndex(): void
    {
        $index = new IndexCompareResult(
            'idx_email_unique',
            CompareResult::OPERATION_CREATE,
            ['email'],
            true,
            false
        );

        $result = $this->generator->testGenerateIndexSql($index, 'users', true);

        $this->assertEquals('ADD UNIQUE INDEX "idx_email_unique" ("email")', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateIndexSqlWithoutAddPrefix(): void
    {
        $index = new IndexCompareResult(
            'idx_name',
            CompareResult::OPERATION_CREATE,
            ['name'],
            false,
            false
        );

        $result = $this->generator->testGenerateIndexSql($index, 'users', false);

        $this->assertEquals('INDEX "idx_name" ("name")', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMapTypeLengthReturnsTextForUnknownType(): void
    {
        $column = new PropertiesData(type: null);

        $result = $this->generator->testMapTypeLength($column);

        $this->assertEquals('TEXT', $result);
    }

    public function testMapTypeLengthReturnsDatabaseType(): void
    {
        $column = new PropertiesData(type: 'int');

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('int')
            ->willReturn('INTEGER');

        $result = $this->generator->testMapTypeLength($column);

        $this->assertEquals('INTEGER', $result);
    }

    public function testMapTypeLengthHandlesStringWithLength(): void
    {
        $column = new PropertiesData(type: 'string', length: 100);

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('string')
            ->willReturn('VARCHAR(255)');

        $result = $this->generator->testMapTypeLength($column);

        $this->assertEquals('VARCHAR(100)', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetConcurrentIndexPrefixReturnsEmptyByDefault(): void
    {
        $result = $this->generator->testGetConcurrentIndexPrefix();

        $this->assertEquals('', $result);
    }
    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateFiltersOutEmptyAlterStatement(): void
    {
        $generator = new EmptyStatementMigrationGenerator($this->typeRegistry);
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_UPDATE,
            [],
            [],
            [],
            []
        );

        $result = $generator->generate($compareResult);

        $this->assertEquals([], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRollbackFiltersOutEmptyCreateTableFromRollbackStatement(): void
    {
        $generator = new EmptyStatementMigrationGenerator($this->typeRegistry);
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_DELETE,
            [],
            [],
            [],
            []
        );

        $result = $generator->rollback($compareResult);

        $this->assertEquals([], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRollbackFiltersOutEmptyAlterTableRollbackStatement(): void
    {
        $generator = new EmptyStatementMigrationGenerator($this->typeRegistry);
        $compareResult = new TableCompareResult(
            'test_table',
            CompareResult::OPERATION_UPDATE,
            [],
            [],
            [],
            []
        );

        $result = $generator->rollback($compareResult);

        $this->assertEquals([], $result);
    }

    public function testColumnDefinitionEscapesSingleQuoteInDefaultValue(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            defaultValue: "O'Brien",
            isPrimaryKey: false,
            isAutoIncrement: false
        );

        $this->typeRegistry->expects($this->once())
            ->method('getDatabaseType')
            ->with('string')
            ->willReturn('VARCHAR(255)');

        $result = $this->generator->testColumnDefinition('name', $column);

        $this->assertEquals("\"name\" VARCHAR(255) NOT NULL DEFAULT 'O''Brien'", $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateIndexSqlUsesConcurrentPrefixWhenConcurrent(): void
    {
        $generator = new EmptyStatementMigrationGenerator($this->typeRegistry);
        $index = new IndexCompareResult(
            'idx_name',
            CompareResult::OPERATION_CREATE,
            ['name'],
            false,
            true
        );

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('generateIndexSql');
        $method->setAccessible(true);
        $result = $method->invoke($generator, $index, 'users', true);

        $this->assertEquals('ADD CONCURRENT INDEX "idx_name" ("name")', $result);
    }
}

/**
 * Test implementation whose alter/rollback hooks return empty strings so the
 * array_filter() calls around generate()/rollback() actually drop entries.
 */
class EmptyStatementMigrationGenerator extends AbstractMigrationGenerator {
    public function getIdentifierQuote(): string
    {
        return '"';
    }

    protected function generateDropTable(string $tableName): string
    {
        return '';
    }

    protected function generateCreateTable(TableCompareResult $compareResult): string
    {
        return '';
    }

    protected function generateAlterTable(TableCompareResult $compareResult): string
    {
        return '';
    }

    protected function generateAlterTableRollback(TableCompareResult $compareResult): string
    {
        return '';
    }

    protected function generateCreateTableFromRollback(TableCompareResult $compareResult): string
    {
        return '';
    }

    protected function getForeignKeyKeyword(): string
    {
        return 'CONSTRAINT';
    }

    protected function getDropForeignKeySyntax(string $constraintName): string
    {
        return '';
    }

    protected function getDropIndexSyntax(string $indexName): string
    {
        return '';
    }

    protected function getModifyColumnSyntax(string $columnName, PropertiesData $column): string
    {
        return '';
    }

    protected function getPrimaryKeyGenerationSql(string $generatorType, ?string $sequence = null): string
    {
        return '';
    }

    protected function getAutoIncrementSql(): string
    {
        return '';
    }

    protected function getConcurrentIndexPrefix(): string
    {
        return 'CONCURRENT ';
    }
}
class TestMigrationGenerator extends AbstractMigrationGenerator {
    public function getIdentifierQuote(): string
    {
        return '"';
    }

    protected function generateDropTable(string $tableName): string
    {
        return "DROP TABLE {$tableName}";
    }

    protected function generateCreateTable(TableCompareResult $compareResult): string
    {
        return "CREATE TABLE {$compareResult->name}";
    }

    protected function generateAlterTable(TableCompareResult $compareResult): string
    {
        return "ALTER TABLE {$compareResult->name}";
    }

    protected function generateAlterTableRollback(TableCompareResult $compareResult): string
    {
        return "ALTER TABLE {$compareResult->name} ROLLBACK";
    }

    protected function generateCreateTableFromRollback(TableCompareResult $compareResult): string
    {
        return "CREATE TABLE {$compareResult->name} FROM ROLLBACK";
    }

    protected function getForeignKeyKeyword(): string
    {
        return 'CONSTRAINT';
    }

    protected function getDropForeignKeySyntax(string $constraintName): string
    {
        return "DROP CONSTRAINT {$constraintName}";
    }

    protected function getDropIndexSyntax(string $indexName): string
    {
        return "DROP INDEX {$indexName}";
    }

    protected function getModifyColumnSyntax(string $columnName, PropertiesData $column): string
    {
        return "MODIFY COLUMN {$columnName}";
    }

    protected function getPrimaryKeyGenerationSql(string $generatorType, ?string $sequence = null): string
    {
        return 'GENERATED BY IDENTITY';
    }

    protected function getAutoIncrementSql(): string
    {
        return 'AUTO_INCREMENT';
    }

    // Public test methods
    public function testColumnDefinition(string $name, PropertiesData $column): string
    {
        return $this->columnDefinition($name, $column);
    }

    public function testForeignKeyDefinition(ForeignKeyCompareResult $foreignKey, bool $withAdd = true): string
    {
        return $this->foreignKeyDefinition($foreignKey, $withAdd);
    }

    public function testGenerateIndexSql(IndexCompareResult $index, string $tableName, bool $withAdd = true): string
    {
        return $this->generateIndexSql($index, $tableName, $withAdd);
    }

    public function testMapTypeLength(?PropertiesData $propertyData): string
    {
        return $this->mapTypeLength($propertyData);
    }

    public function testGetConcurrentIndexPrefix(): string
    {
        return $this->getConcurrentIndexPrefix();
    }
}
