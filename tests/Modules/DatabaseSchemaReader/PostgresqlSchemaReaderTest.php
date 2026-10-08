<?php

namespace Articulate\Tests\Modules\DatabaseSchemaReader;

use Articulate\Connection;
use Articulate\Exceptions\DatabaseSchemaException;
use Articulate\Modules\Database\SchemaReader\DatabaseColumn;
use Articulate\Modules\Database\SchemaReader\PostgresqlSchemaReader;
use Articulate\Tests\AbstractTestCase;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class PostgresqlSchemaReaderTest extends AbstractTestCase {
    private PostgresqlSchemaReader $reader;

    private Connection $mockConnection;

    private PDOStatement $mockStatement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConnection = $this->createMock(Connection::class);
        $this->mockStatement = $this->createStub(PDOStatement::class);
        $this->reader = new PostgresqlSchemaReader($this->mockConnection);
    }

    public function testGetTableColumnsReturnsColumns(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                [
                    'column_name' => 'id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => "nextval('test_table_id_seq'::regclass)",
                    'character_maximum_length' => null,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                ],
                [
                    'column_name' => 'name',
                    'data_type' => 'character varying',
                    'is_nullable' => 'YES',
                    'column_default' => "'default_name'::character varying",
                    'character_maximum_length' => 255,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                ],
                [
                    'column_name' => 'price',
                    'data_type' => 'numeric',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => null,
                    'numeric_precision' => 10,
                    'numeric_scale' => 2,
                ],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('information_schema.columns'),
                ['table' => $tableName]
            )
            ->willReturn($this->mockStatement);

        $columns = $this->reader->getTableColumns($tableName);

        $this->assertCount(3, $columns);
        $this->assertContainsOnlyInstancesOf(DatabaseColumn::class, $columns);

        // Test first column (id)
        $idColumn = $columns[0];
        $this->assertEquals('id', $idColumn->name);
        $this->assertEquals('integer', $idColumn->type);
        $this->assertFalse($idColumn->isNullable);
        $this->assertEquals("nextval('test_table_id_seq')", $idColumn->defaultValue);

        // Test second column (name)
        $nameColumn = $columns[1];
        $this->assertEquals('name', $nameColumn->name);
        $this->assertEquals('VARCHAR', $nameColumn->type);
        $this->assertEquals('string', $nameColumn->phpType);
        $this->assertTrue($nameColumn->isNullable);
        $this->assertEquals('default_name', $nameColumn->defaultValue);
        $this->assertEquals(255, $nameColumn->length);

        // Test third column (price)
        $priceColumn = $columns[2];
        $this->assertEquals('price', $priceColumn->name);
        $this->assertEquals('numeric', $priceColumn->type);
        $this->assertEquals('float', $priceColumn->phpType);
        $this->assertFalse($priceColumn->isNullable);
        $this->assertNull($priceColumn->defaultValue);
        $this->assertEquals(10, $priceColumn->length);
    }

    public function testGetTableColumnsHandlesPdoException(): void
    {
        $tableName = 'test_table';

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new PDOException('Database connection failed'));

        $this->expectException(DatabaseSchemaException::class);
        $this->expectExceptionMessage("Failed to retrieve columns for table 'test_table': Database connection failed");

        $this->reader->getTableColumns($tableName);
    }

    public function testGetTablesReturnsTableNames(): void
    {
        $this->mockStatement->method('fetchAll')
            ->willReturn(['users', 'products', 'orders']);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('information_schema.tables'))
            ->willReturn($this->mockStatement);

        $tables = $this->reader->getTables();

        $this->assertEquals(['users', 'products', 'orders'], $tables);
    }

    public function testGetTableIndexesReturnsIndexes(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                [
                    'index_name' => 'idx_name',
                    'column_name' => 'name',
                    'is_unique' => false,
                    'is_primary' => false,
                ],
                [
                    'index_name' => 'idx_email',
                    'column_name' => 'email',
                    'is_unique' => true,
                    'is_primary' => false,
                ],
                [
                    'index_name' => 'idx_composite',
                    'column_name' => 'first_name',
                    'is_unique' => false,
                    'is_primary' => false,
                ],
                [
                    'index_name' => 'idx_composite',
                    'column_name' => 'last_name',
                    'is_unique' => false,
                    'is_primary' => false,
                ],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('pg_indexes'),
                ['table' => $tableName]
            )
            ->willReturn($this->mockStatement);

        $indexes = $this->reader->getTableIndexes($tableName);

        $this->assertCount(3, $indexes);

        // Test idx_name index
        $this->assertArrayHasKey('idx_name', $indexes);
        $this->assertEquals(['name'], $indexes['idx_name']['columns']);
        $this->assertFalse($indexes['idx_name']['unique']);

        // Test idx_email unique index
        $this->assertArrayHasKey('idx_email', $indexes);
        $this->assertEquals(['email'], $indexes['idx_email']['columns']);
        $this->assertTrue($indexes['idx_email']['unique']);

        // Test composite index
        $this->assertArrayHasKey('idx_composite', $indexes);
        $this->assertEquals(['first_name', 'last_name'], $indexes['idx_composite']['columns']);
        $this->assertFalse($indexes['idx_composite']['unique']);
    }

    public function testGetTableIndexesHandlesMissingData(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                ['index_name' => null, 'column_name' => 'name'],
                ['index_name' => 'idx_name', 'column_name' => null],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->mockStatement);

        $indexes = $this->reader->getTableIndexes($tableName);

        $this->assertEmpty($indexes);
    }

    public function testGetTableForeignKeysReturnsForeignKeys(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                [
                    'constraint_name' => 'fk_user_id',
                    'column_name' => 'user_id',
                    'referenced_table_name' => 'users',
                    'referenced_column_name' => 'id',
                ],
                [
                    'constraint_name' => 'fk_category_id',
                    'column_name' => 'category_id',
                    'referenced_table_name' => 'categories',
                    'referenced_column_name' => 'id',
                ],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('table_constraints'),
                ['table' => $tableName]
            )
            ->willReturn($this->mockStatement);

        $foreignKeys = $this->reader->getTableForeignKeys($tableName);

        $this->assertCount(2, $foreignKeys);

        // Test first foreign key
        $this->assertArrayHasKey('fk_user_id', $foreignKeys);
        $this->assertEquals('user_id', $foreignKeys['fk_user_id']['column']);
        $this->assertEquals('users', $foreignKeys['fk_user_id']['referencedTable']);
        $this->assertEquals('id', $foreignKeys['fk_user_id']['referencedColumn']);

        // Test second foreign key
        $this->assertArrayHasKey('fk_category_id', $foreignKeys);
        $this->assertEquals('category_id', $foreignKeys['fk_category_id']['column']);
        $this->assertEquals('categories', $foreignKeys['fk_category_id']['referencedTable']);
        $this->assertEquals('id', $foreignKeys['fk_category_id']['referencedColumn']);
    }

    public function testGetTableForeignKeysHandlesMissingData(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                ['constraint_name' => null, 'column_name' => 'user_id'],
                ['constraint_name' => 'fk_user', 'column_name' => null],
                ['constraint_name' => 'fk_cat', 'column_name' => 'cat_id', 'referenced_table_name' => null],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->mockStatement);

        $foreignKeys = $this->reader->getTableForeignKeys($tableName);

        $this->assertEmpty($foreignKeys);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildPostgresqlTypeStringHandlesSpecialTypes(): void
    {
        $reflection = new \ReflectionClass(PostgresqlSchemaReader::class);
        $method = $reflection->getMethod('buildPostgresqlTypeString');
        $method->setAccessible(true);

        $testCases = [
            [
                'data_type' => 'character varying',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'VARCHAR',
            ],
            [
                'data_type' => 'character',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'CHAR',
            ],
            [
                'data_type' => 'double precision',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'DOUBLE',
            ],
            [
                'data_type' => 'timestamp without time zone',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'TIMESTAMP',
            ],
            [
                'data_type' => 'timestamp with time zone',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'TIMESTAMPTZ',
            ],
            [
                'data_type' => 'integer',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'integer',
            ],
        ];

        foreach ($testCases as $case) {
            $result = $method->invoke($this->reader, $case);
            $this->assertEquals($case['expected'], $result, "Failed for type: {$case['data_type']}");
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildPostgresqlTypeStringHandlesLength(): void
    {
        $reflection = new \ReflectionClass(PostgresqlSchemaReader::class);
        $method = $reflection->getMethod('buildPostgresqlTypeString');
        $method->setAccessible(true);

        $testCases = [
            [
                'data_type' => 'character varying',
                'character_maximum_length' => 255,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'expected' => 'VARCHAR(255)',
            ],
            [
                'data_type' => 'numeric',
                'character_maximum_length' => null,
                'numeric_precision' => 10,
                'numeric_scale' => 2,
                'expected' => 'numeric(10,2)',
            ],
            [
                'data_type' => 'numeric',
                'character_maximum_length' => null,
                'numeric_precision' => 5,
                'numeric_scale' => null,
                'expected' => 'numeric(5,0)',
            ],
        ];

        foreach ($testCases as $case) {
            $result = $method->invoke($this->reader, $case);
            $this->assertEquals($case['expected'], $result);
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNormalizeDefaultValueHandlesVariousFormats(): void
    {
        $reflection = new \ReflectionClass(PostgresqlSchemaReader::class);
        $method = $reflection->getMethod('normalizeDefaultValue');
        $method->setAccessible(true);

        $testCases = [
            [null, null],
            ["'default_value'::text", 'default_value'],
            ["'hello'::character varying", 'hello'],
            ["nextval('sequence_name'::regclass)", "nextval('sequence_name')"],
            ["'literal_string'", "'literal_string'"],
        ];

        foreach ($testCases as [$input, $expected]) {
            $result = $method->invoke($this->reader, $input);
            $this->assertEquals($expected, $result, 'Failed for input: ' . var_export($input, true));
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTableColumnsExceptionUsesZeroCode(): void
    {
        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new PDOException('boom'));

        try {
            $this->reader->getTableColumns('test_table');
            $this->fail('Expected DatabaseSchemaException.');
        } catch (DatabaseSchemaException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    public function testGetTableIndexesNormalizesUppercaseColumnKeys(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                [
                    'INDEX_NAME' => 'idx_name',
                    'COLUMN_NAME' => 'name',
                    'IS_UNIQUE' => false,
                    'IS_PRIMARY' => false,
                ],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->mockStatement);

        $indexes = $this->reader->getTableIndexes($tableName);

        $this->assertArrayHasKey('idx_name', $indexes);
        $this->assertEquals(['name'], $indexes['idx_name']['columns']);
    }

    public function testGetTableIndexesMarksPrimaryColumnsAsUniqueEvenWhenNotExplicitlyUnique(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                [
                    'index_name' => 'test_table_pkey',
                    'column_name' => 'id',
                    'is_unique' => false,
                    'is_primary' => true,
                ],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->mockStatement);

        $indexes = $this->reader->getTableIndexes($tableName);

        $this->assertTrue($indexes['test_table_pkey']['unique']);
    }

    public function testGetTableForeignKeysNormalizesUppercaseColumnKeys(): void
    {
        $tableName = 'test_table';

        $this->mockStatement->method('fetchAll')
            ->willReturn([
                [
                    'CONSTRAINT_NAME' => 'fk_user_id',
                    'COLUMN_NAME' => 'user_id',
                    'REFERENCED_TABLE_NAME' => 'users',
                    'REFERENCED_COLUMN_NAME' => 'id',
                ],
            ]);

        $this->mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->mockStatement);

        $foreignKeys = $this->reader->getTableForeignKeys($tableName);

        $this->assertArrayHasKey('fk_user_id', $foreignKeys);
        $this->assertEquals('user_id', $foreignKeys['fk_user_id']['column']);
    }
}
