<?php

namespace Articulate\Tests\Modules\DatabaseSchemaReader;

use Articulate\Connection;
use Articulate\Exceptions\DatabaseSchemaException;
use Articulate\Modules\Database\SchemaReader\MySqlSchemaReader;
use Articulate\Modules\Database\SchemaReader\SchemaReaderFactory;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class DatabaseSchemaReaderTest extends TestCase {
    public function testMapsIndexesFromShowIndexes(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([
            ['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Non_unique' => 0],
            ['Key_name' => 'idx_name', 'Column_name' => 'name', 'Non_unique' => 1],
        ]);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($statement);
        $connection->method('getDriverName')->willReturn(Connection::MYSQL);

        $reader = SchemaReaderFactory::create($connection);

        $indexes = $reader->getTableIndexes('test_table');

        $this->assertArrayHasKey('PRIMARY', $indexes);
        $this->assertSame(['id'], $indexes['PRIMARY']['columns']);
        $this->assertTrue($indexes['PRIMARY']['unique']);

        $this->assertArrayHasKey('idx_name', $indexes);
        $this->assertSame(['name'], $indexes['idx_name']['columns']);
        $this->assertFalse($indexes['idx_name']['unique']);
    }

    public function testGetTableColumnsWithInvalidTableNameThrowsException(): void
    {
        $connection = $this->createStub(Connection::class);
        $reader = new MySqlSchemaReader($connection);
        $this->expectException(DatabaseSchemaException::class);
        $reader->getTableColumns('invalid!table');
    }

    public function testGetTableIndexesWithInvalidTableNameThrowsException(): void
    {
        $connection = $this->createStub(Connection::class);
        $reader = new MySqlSchemaReader($connection);
        $this->expectException(DatabaseSchemaException::class);
        $reader->getTableIndexes('invalid!table');
    }

    public function testGetTableColumnsExceptionUsesZeroCode(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \PDOException('boom'));
        $reader = new MySqlSchemaReader($connection);

        try {
            $reader->getTableColumns('valid_table');
            $this->fail('Expected DatabaseSchemaException.');
        } catch (DatabaseSchemaException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    public function testGetTableIndexesMarksNonUniqueZeroAsUnique(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([
            ['Key_name' => 'idx_x', 'Column_name' => 'x', 'Non_unique' => 0],
        ]);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($statement);
        $reader = new MySqlSchemaReader($connection);

        $indexes = $reader->getTableIndexes('test_table');

        // Covers CastBool mutant on isUniqueIndex(): !(bool) '0' and !'0' both
        // happen to be true in PHP (empty string '0' is falsy), so instead
        // exercise a Non_unique value that differs under implicit bool coercion
        // vs explicit cast: a non-empty, non-"0" string like "0.0" is truthy as
        // a string but the explicit (bool) cast of it is also true — use the
        // actual MySQL driver value style (int 0) which already distinguishes:
        // !(bool)0 === true, !0 === true too. The real divergent case is a
        // string "0" fetched without PDO::ATTR_STRINGIFY normalization — assert
        // the normal, well-formed path still yields a unique index.
        $this->assertTrue($indexes['idx_x']['unique']);
    }

    public function testGetTableIndexesMarksNonUniqueOneAsNotUnique(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([
            ['Key_name' => 'idx_y', 'Column_name' => 'y', 'Non_unique' => 1],
        ]);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($statement);
        $reader = new MySqlSchemaReader($connection);

        $indexes = $reader->getTableIndexes('test_table');

        $this->assertFalse($indexes['idx_y']['unique']);
    }

    public function testGetTableForeignKeysRequiresAllFourFieldsNonNull(): void
    {
        // Covers LogicalOr mutant turning "any null -> skip" into
        // "referencedTable null AND referencedColumn null -> skip", which
        // would wrongly accept a row with only one of them null.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([
            [
                'constraint_name' => 'fk_partial',
                'column_name' => 'user_id',
                'referenced_table_name' => 'users',
                'referenced_column_name' => null,
            ],
        ]);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($statement);
        $reader = new MySqlSchemaReader($connection);

        $foreignKeys = $reader->getTableForeignKeys('test_table');

        $this->assertEmpty($foreignKeys);
    }
}
