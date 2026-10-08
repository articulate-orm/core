<?php

namespace Articulate\Tests\Modules\Migrations;

use Articulate\Modules\Database\PostgresqlTypeMapper;
use Articulate\Modules\Database\SchemaComparator\Models\ColumnCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\CompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\ForeignKeyCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\IndexCompareResult;
use Articulate\Modules\Database\SchemaComparator\Models\PropertiesData;
use Articulate\Modules\Database\SchemaComparator\Models\TableCompareResult;
use Articulate\Modules\Migrations\Generator\PostgresqlMigrationGenerator;
use Articulate\Tests\AbstractTestCase;

class PostgresqlMigrationGeneratorTest extends AbstractTestCase {
    private PostgresqlMigrationGenerator $generator;

    private PostgresqlTypeMapper $typeMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->typeMapper = new PostgresqlTypeMapper();
        $this->generator = new PostgresqlMigrationGenerator($this->typeMapper);
    }

    public function testGetIdentifierQuoteReturnsDoubleQuote(): void
    {
        $this->assertEquals('"', $this->generator->getIdentifierQuote());
    }

    public function testGenerateDropTableReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('generateDropTable', ['users']);

        $this->assertEquals('DROP TABLE "users"', $result);
    }

    public function testColumnDefinitionGeneratesBasicColumn(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            length: 255
        );

        $result = $this->callProtectedMethod('columnDefinition', ['name', $column]);

        $this->assertEquals('"name" VARCHAR(255) NOT NULL', $result);
    }

    public function testColumnDefinitionHandlesNullableColumn(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: true,
            length: 255
        );

        $result = $this->callProtectedMethod('columnDefinition', ['email', $column]);

        $this->assertEquals('"email" VARCHAR(255)', $result);
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

        $this->assertEquals('"status" VARCHAR(255) NOT NULL DEFAULT \'default_value\'', $result);
    }

    public function testColumnDefinitionHandlesPrimaryKeyGeneration(): void
    {
        $column = new PropertiesData(
            type: 'int',
            isNullable: false,
            generatorType: 'serial',
            sequence: 'user_seq',
            isPrimaryKey: true
        );

        $result = $this->callProtectedMethod('columnDefinition', ['id', $column]);

        $this->assertStringContainsString("DEFAULT nextval('user_seq')", $result);
    }

    public function testGetForeignKeyKeywordReturnsConstraint(): void
    {
        $result = $this->callProtectedMethod('getForeignKeyKeyword', []);

        $this->assertEquals('CONSTRAINT', $result);
    }

    public function testGetDropForeignKeySyntaxReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('getDropForeignKeySyntax', ['fk_user_id']);

        $this->assertEquals('DROP CONSTRAINT "fk_user_id"', $result);
    }

    public function testGetDropIndexSyntaxReturnsCorrectSql(): void
    {
        $result = $this->callProtectedMethod('getDropIndexSyntax', ['idx_name']);

        $this->assertEquals('DROP INDEX "idx_name"', $result);
    }

    public function testGetModifyColumnSyntaxReturnsCorrectSql(): void
    {
        $column = new PropertiesData(
            type: 'string',
            isNullable: false,
            length: 255
        );

        $result = $this->callProtectedMethod('getModifyColumnSyntax', ['email', $column]);

        $this->assertEquals('ALTER COLUMN "email" TYPE VARCHAR(255)', $result);
    }

    public function testModifyDatetimeColumnUsesPostgresqlTypeSyntax(): void
    {
        $result = $this->generator->generate(new TableCompareResult(
            name: 'categories_products',
            operation: CompareResult::OPERATION_UPDATE,
            columns: [
                new ColumnCompareResult(
                    name: 'assigned_at',
                    operation: CompareResult::OPERATION_UPDATE,
                    propertyData: new PropertiesData(type: 'datetime', isNullable: true),
                    columnData: new PropertiesData(type: 'string', isNullable: true, length: 255),
                ),
            ],
        ));

        $this->assertEquals([
            'ALTER TABLE "categories_products" ALTER COLUMN "assigned_at" TYPE TIMESTAMP',
        ], $result);
        $this->assertStringNotContainsString(
            'ALTER COLUMN "assigned_at" TIMESTAMP',
            implode(' ', $result)
        );
    }

    public function testRollbackModifyDatetimeColumnUsesPostgresqlTypeSyntax(): void
    {
        $result = $this->generator->rollback(new TableCompareResult(
            name: 'categories_products',
            operation: CompareResult::OPERATION_UPDATE,
            columns: [
                new ColumnCompareResult(
                    name: 'assigned_at',
                    operation: CompareResult::OPERATION_UPDATE,
                    propertyData: new PropertiesData(type: 'string', isNullable: true, length: 255),
                    columnData: new PropertiesData(type: 'datetime', isNullable: true),
                ),
            ],
        ));

        $this->assertEquals([
            'ALTER TABLE "categories_products" ALTER COLUMN "assigned_at" TYPE TIMESTAMP',
        ], $result);
        $this->assertStringNotContainsString(
            'ALTER COLUMN "assigned_at" TIMESTAMP',
            implode(' ', $result)
        );
    }

    public function testModifyNullableAndDefaultOnlyChangesUseSeparatePostgresqlClauses(): void
    {
        $result = $this->generator->generate(new TableCompareResult(
            name: 'users',
            operation: CompareResult::OPERATION_UPDATE,
            columns: [
                new ColumnCompareResult(
                    name: 'status',
                    operation: CompareResult::OPERATION_UPDATE,
                    propertyData: new PropertiesData(type: 'string', isNullable: false, defaultValue: 'active', length: 32),
                    columnData: new PropertiesData(type: 'string', isNullable: true, defaultValue: null, length: 32),
                ),
            ],
        ));

        $this->assertEquals([
            'ALTER TABLE "users" ALTER COLUMN "status" SET NOT NULL, ALTER COLUMN "status" SET DEFAULT \'active\'',
        ], $result);
    }

    public function testModifyDropsNullableAndDefaultWithSeparatePostgresqlClauses(): void
    {
        $result = $this->generator->generate(new TableCompareResult(
            name: 'users',
            operation: CompareResult::OPERATION_UPDATE,
            columns: [
                new ColumnCompareResult(
                    name: 'status',
                    operation: CompareResult::OPERATION_UPDATE,
                    propertyData: new PropertiesData(type: 'string', isNullable: true, defaultValue: null, length: 32),
                    columnData: new PropertiesData(type: 'string', isNullable: false, defaultValue: 'active', length: 32),
                ),
            ],
        ));

        $this->assertEquals([
            'ALTER TABLE "users" ALTER COLUMN "status" DROP NOT NULL, ALTER COLUMN "status" DROP DEFAULT',
        ], $result);
    }

    public function testGetConcurrentIndexPrefixReturnsConcurrently(): void
    {
        $result = $this->callProtectedMethod('getConcurrentIndexPrefix', []);

        $this->assertEquals('CONCURRENTLY ', $result);
    }

    public function testGetPrimaryKeyGenerationSqlReturnsCorrectSqlForSerial(): void
    {
        $result = $this->callProtectedMethod('getPrimaryKeyGenerationSql', ['serial', 'user_seq']);

        $this->assertEquals("DEFAULT nextval('user_seq')", $result);
    }

    public function testGetPrimaryKeyGenerationSqlReturnsCorrectSqlForBigserial(): void
    {
        $result = $this->callProtectedMethod('getPrimaryKeyGenerationSql', ['bigserial', 'user_seq']);

        $this->assertEquals("DEFAULT nextval('user_seq')", $result);
    }

    public function testGetPrimaryKeyGenerationSqlReturnsEmptyForUnknown(): void
    {
        $result = $this->callProtectedMethod('getPrimaryKeyGenerationSql', ['unknown_type']);

        $this->assertEquals('', $result);
    }

    public function testGetAutoIncrementSqlReturnsIdentity(): void
    {
        $result = $this->callProtectedMethod('getAutoIncrementSql', []);

        $this->assertEquals('GENERATED ALWAYS AS IDENTITY', $result);
    }

    public function testMapTypeLengthReturnsDatabaseType(): void
    {
        $column = new PropertiesData(type: 'int');
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('INTEGER', $result);

        // Test with string length
        $column = new PropertiesData(type: 'string', length: 100);
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('VARCHAR(100)', $result);
    }

    public function testMapTypeLengthHandlesPostgresqlSpecificTypes(): void
    {
        // Test mixed type (maps to TEXT)
        $column = new PropertiesData(type: 'mixed');
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('TEXT', $result);

        // Test UUID type
        $column = new PropertiesData(type: 'uuid');
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('UUID', $result);

        // Test JSON type
        $column = new PropertiesData(type: 'json');
        $result = $this->callProtectedMethod('mapTypeLength', [$column]);
        $this->assertEquals('JSONB', $result);
    }

    public function testRollbackOfDeletedTableRecreatesDeletedIndexes(): void
    {
        $result = $this->generator->rollback(new TableCompareResult(
            name: 'products',
            operation: CompareResult::OPERATION_DELETE,
            columns: [],
            indexes: [
                new IndexCompareResult(
                    'idx_products_sku',
                    CompareResult::OPERATION_DELETE,
                    ['sku'],
                    true,
                ),
            ],
        ));

        $this->assertCount(2, $result);
        $this->assertStringContainsString('CREATE TABLE "products"', $result[0]);
        $this->assertEquals(
            'CREATE UNIQUE INDEX "idx_products_sku" ON "products" ("sku")',
            $result[1],
        );
    }

    public function testGenerateAlterTableCombinesMultipleForeignKeyChanges(): void
    {
        $result = $this->generator->generate(new TableCompareResult(
            name: 'posts',
            operation: CompareResult::OPERATION_UPDATE,
            foreignKeys: [
                new ForeignKeyCompareResult(
                    'fk_posts_author_id',
                    CompareResult::OPERATION_CREATE,
                    'author_id',
                    'users',
                    'id',
                ),
                new ForeignKeyCompareResult(
                    'fk_posts_category_id',
                    CompareResult::OPERATION_CREATE,
                    'category_id',
                    'categories',
                    'id',
                ),
            ],
        ));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('fk_posts_author_id', $result[0]);
        $this->assertStringContainsString('fk_posts_category_id', $result[0]);
    }

    public function testFormatDefaultValueEscapesSingleQuotes(): void
    {
        $result = $this->callProtectedMethod('formatDefaultValue', ["O'Brien"]);

        $this->assertEquals("'O''Brien'", $result);
    }

    public function testReverseOperationKeepsUnknownOperationUnchanged(): void
    {
        $result = $this->callProtectedMethod('reverseOperation', ['some_unknown_operation']);

        $this->assertEquals('some_unknown_operation', $result);
    }

    public function testGenerateModifyColumnOnlyCollectsChangesWhenPresent(): void
    {
        // No type/length/nullable/default change: isRollback default false must not flip
        // the data source used, so this must compare propertyData not columnData.
        $result = $this->generator->generate(new TableCompareResult(
            name: 'users',
            operation: CompareResult::OPERATION_UPDATE,
            columns: [
                new ColumnCompareResult(
                    name: 'status',
                    operation: CompareResult::OPERATION_UPDATE,
                    propertyData: new PropertiesData(type: 'string', isNullable: true, length: 32),
                    columnData: new PropertiesData(type: 'string', isNullable: true, length: 32),
                ),
            ],
        ));

        $this->assertEquals([], $result);
    }

    private function callProtectedMethod(string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->generator, $args);
    }
}
