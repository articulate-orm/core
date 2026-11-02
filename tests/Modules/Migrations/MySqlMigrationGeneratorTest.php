<?php

namespace Articulate\Tests\Modules\Migrations;

use Articulate\Modules\Database\MySqlTypeMapper;
use Articulate\Modules\Database\SchemaComparator\Models\CompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\ForeignKeyCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\IndexCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\PropertiesData;
use Articulate\Modules\Database\SchemaComparator\Models\TableCompareResult;
use Articulate\Modules\Migrations\Generator\MySqlMigrationGenerator;
use Articulate\Tests\AbstractTestCase;

class MySqlMigrationGeneratorTest extends AbstractTestCase {
    private MySqlMigrationGenerator $generator;

    private MySqlTypeMapper $typeMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->typeMapper = new MySqlTypeMapper();
        $this->generator = new MySqlMigrationGenerator($this->typeMapper);
    }

    public function testGetIdentifierQuoteReturnsBacktick(): void
    {
        $this->assertEquals('`', $this->generator->getIdentifierQuote());
    }

    public function testGenerateDropTableReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('generateDropTable', ['users']);

        $this->assertEquals('DROP TABLE `users`', $result);
    }

    public function testColumnDefinitionGeneratesBasicColumn(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            length: 255
        );

        $result = $this->callProtectedMethod('columnDefinition', ['name', $column]);

        $this->assertEquals('`name` VARCHAR(255) NOT NULL', $result);
    }

    public function testColumnDefinitionHandlesNullableColumn(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: true,
            length: 255
        );

        $result = $this->callProtectedMethod('columnDefinition', ['email', $column]);

        $this->assertEquals('`email` VARCHAR(255)', $result);
    }

    public function testColumnDefinitionHandlesAutoIncrement(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: false,
            isAutoIncrement: true
        );

        $result = $this->callProtectedMethod('columnDefinition', ['id', $column]);

        $this->assertEquals('`id` INT AUTO_INCREMENT NOT NULL', $result);
    }

    public function testColumnDefinitionHandlesIntegerPrimaryKeyAsUnsigned(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: false,
            isPrimaryKey: true
        );

        $result = $this->callProtectedMethod('columnDefinition', ['id', $column]);

        $this->assertEquals('`id` INT UNSIGNED NOT NULL', $result);
    }

    public function testGetForeignKeyKeywordReturnsConstraint(): void
    {
        $result = $this->callProtectedMethod('getForeignKeyKeyword', []);

        $this->assertEquals('CONSTRAINT', $result);
    }

    public function testGetDropForeignKeySyntaxReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('getDropForeignKeySyntax', ['fk_user_id']);

        $this->assertEquals('DROP FOREIGN KEY `fk_user_id`', $result);
    }

    public function testGetDropIndexSyntaxReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('getDropIndexSyntax', ['idx_name']);

        $this->assertEquals('DROP INDEX `idx_name`', $result);
    }

    public function testGetPrimaryKeyGenerationSqlReturnsEmptyForUnknown(): void
    {
        $result = $this->callProtectedMethod('getPrimaryKeyGenerationSql', ['unknown_type']);

        $this->assertEquals('', $result);
    }

    public function testGetAutoIncrementSqlReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('getAutoIncrementSql', []);

        $this->assertEquals('AUTO_INCREMENT', $result);
    }

    public function testGetModifyColumnSyntaxReturnsCorrectSql(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            length: 255
        );

        $result = $this->callProtectedMethod('getModifyColumnSyntax', ['email', $column]);

        $this->assertEquals('MODIFY `email` `email` VARCHAR(255) NOT NULL', $result);
    }

    public function testShouldUseOnlineDDLReturnsFalseByDefault(): void
    {
        $tableResult = new TableCompareResult(
            'users',
            CompareResult::OPERATION_UPDATE,
            [],
            [],
            [],
            []
        );

        $result = $this->callProtectedMethod('shouldUseOnlineDDL', [$tableResult]);

        $this->assertFalse($result);
    }

    public function testGetConcurrentIndexPrefixReturnsEmpty(): void
    {
        $result = $this->callProtectedMethod('getConcurrentIndexPrefix', []);

        $this->assertEquals('', $result);
    }

    public function testMapTypeLengthReturnsDatabaseType(): void
    {
        $column = new PropertiesData(type: 'int');
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('INT', $result);

        // Test with string length
        $column = new PropertiesData(type: 'string', length: 100);
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('VARCHAR(100)', $result);

        // PK int → INT UNSIGNED
        $column = new PropertiesData(type: 'int', isPrimaryKey: true);
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('INT UNSIGNED', $result);

        // FK int → INT UNSIGNED
        $column = new PropertiesData(type: 'int', isForeignKey: true);
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('INT UNSIGNED', $result);
    }

    public function testColumnDefinitionHandlesDefaults(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            defaultValue: 'default_value',
            length: 255
        );

        $result = $this->callProtectedMethod('columnDefinition', ['status', $column]);
        $this->assertEquals("`status` VARCHAR(255) NOT NULL DEFAULT 'default_value'", $result);
    }

    public function testForeignKeyDefinitionUsesBackticks(): void
    {
        $foreignKey = new ForeignKeyCompareResult(
            'fk_user_posts',
            CompareResult::OPERATION_CREATE,
            'user_id',
            'users',
            'id'
        );

        $result = $this->callProtectedMethod('foreignKeyDefinition', [$foreignKey, true]);
        $this->assertStringContainsString('`fk_user_posts`', $result);
        $this->assertStringContainsString('`user_id`', $result);
        $this->assertStringContainsString('`users`', $result);
        $this->assertStringContainsString('`id`', $result);
    }

    public function testGenerateIndexSqlHandlesMySqlFeatures(): void
    {
        $index = new IndexCompareResult(
            'idx_composite',
            CompareResult::OPERATION_CREATE,
            ['first_name', 'last_name'],
            false,
            false
        );

        $result = $this->callProtectedMethod('generateIndexSql', [$index, 'users', true]);
        $this->assertEquals('ADD INDEX `idx_composite` (`first_name`, `last_name`)', $result);

        // Test unique index
        $uniqueIndex = new IndexCompareResult(
            'idx_email_unique',
            CompareResult::OPERATION_CREATE,
            ['email'],
            true,
            false
        );

        $result = $this->callProtectedMethod('generateIndexSql', [$uniqueIndex, 'users', true]);
        $this->assertEquals('ADD UNIQUE INDEX `idx_email_unique` (`email`)', $result);
    }

    public function testForeignKeyIntColumnIsUnsigned(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: false,
            isForeignKey: true
        );

        $result = $this->callProtectedMethod('columnDefinition', ['user_id', $column]);

        $this->assertEquals('`user_id` INT UNSIGNED NOT NULL', $result);
    }

    public function testPlainIntColumnStaysSigned(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: true,
        );

        $result = $this->callProtectedMethod('columnDefinition', ['score', $column]);

        $this->assertEquals('`score` INT', $result);
    }

    public function testShouldUseOnlineDDLReturnsTrueForConcurrentIndex(): void
    {
        $tableResult = new TableCompareResult(
            'users',
            CompareResult::OPERATION_UPDATE,
            [],
            [
                new IndexCompareResult('idx_users_email', CompareResult::OPERATION_CREATE, ['email'], true, true),
            ],
            [],
            []
        );

        $result = $this->callProtectedMethod('shouldUseOnlineDDL', [$tableResult]);

        $this->assertTrue($result);
    }

    public function testRollbackOfDeletedTableRecreatesDeletedIndexInline(): void
    {
        $result = $this->generator->rollback(new TableCompareResult(
            name: 'products',
            operation: CompareResult::OPERATION_DELETE,
            columns: [],
            indexes: [
                new IndexCompareResult('idx_products_sku', CompareResult::OPERATION_DELETE, ['sku'], false, false),
            ],
        ));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('CREATE TABLE `products`', $result[0]);
        $this->assertStringContainsString('idx_products_sku', $result[0]);
    }

    public function testReverseOperationKeepsUnknownOperationUnchanged(): void
    {
        $result = $this->callProtectedMethod('reverseOperation', ['some_unknown_operation']);

        $this->assertEquals('some_unknown_operation', $result);
    }

    private function callProtectedMethod(string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->generator, $args);
    }
}
