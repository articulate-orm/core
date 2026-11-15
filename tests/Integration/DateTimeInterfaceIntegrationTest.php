<?php

namespace Articulate\Tests\Integration;

use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Modules\Database\MySqlTypeMapper;
use Articulate\Modules\Database\PostgresqlTypeMapper;
use Articulate\Modules\Database\SchemaComparator\DatabaseSchemaComparator;
use Articulate\Modules\Database\SchemaReader\SchemaReaderFactory;
use Articulate\Modules\Migrations\Generator\MySqlMigrationGenerator;
use Articulate\Modules\Migrations\Generator\PostgresqlMigrationGenerator;
use Articulate\Schema\SchemaNaming;
use Articulate\Tests\AbstractTestCase;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestDateTimeEntity;

/**
 * Integration test to verify DateTimeInterface support works end-to-end.
 */
class DateTimeInterfaceIntegrationTest extends AbstractTestCase {
    public function testDateTimeInterfaceMappingInMigrations(): void
    {
        $connection = $this->getConnection('mysql');
        $connection->executeQuery('DROP TABLE IF EXISTS `test_date_time_entity`');

        $entity = new ReflectionEntity(TestDateTimeEntity::class);
        $reader = SchemaReaderFactory::create($connection);
        $comparator = new DatabaseSchemaComparator($reader, new SchemaNaming());
        $generator = new MySqlMigrationGenerator(new MySqlTypeMapper());

        $compareResults = iterator_to_array($comparator->compareAll([$entity]));
        $compareResult = $compareResults[0]; // Should be the only result

        // Generate the migration SQL
        $statements = $generator->generate($compareResult);
        $sql = implode("\n", $statements);

        // Verify that all DateTime types map to DATETIME
        $this->assertTrue(strpos($sql, '`created_at` DATETIME NOT NULL') !== false, 'DateTime should map to DATETIME');
        $this->assertTrue(strpos($sql, '`updated_at` DATETIME NOT NULL') !== false, 'DateTimeImmutable should map to DATETIME');
        $this->assertTrue(strpos($sql, '`published_at` DATETIME') !== false, 'DateTimeInterface should map to DATETIME');

        // Execute the migration to ensure it works
        foreach ($statements as $statement) {
            $connection->executeQuery($statement);
        }

        // Verify the table was created with correct column types
        $columns = $connection->executeQuery('SHOW COLUMNS FROM `test_date_time_entity`')->fetchAll();

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[$column['Field']] = $column;
        }

        // MySQL returns lowercase in SHOW COLUMNS
        $this->assertEquals('datetime', $columnMap['created_at']['Type']);
        $this->assertEquals('datetime', $columnMap['updated_at']['Type']);
        $this->assertEquals('datetime', $columnMap['published_at']['Type']);
    }

    public function testDateTimeInterfaceMappingInMigrationsPostgresql(): void
    {
        if (!$this->isDatabaseAvailable('pgsql')) {
            $this->markTestSkipped('PostgreSQL is not available.');
        }

        $connection = $this->getConnection('pgsql');
        $connection->executeQuery('DROP TABLE IF EXISTS "test_date_time_entity"');

        $entity = new ReflectionEntity(TestDateTimeEntity::class);
        $reader = SchemaReaderFactory::create($connection);
        $comparator = new DatabaseSchemaComparator($reader, new SchemaNaming());
        $generator = new PostgresqlMigrationGenerator(new PostgresqlTypeMapper());

        $compareResults = iterator_to_array($comparator->compareAll([$entity]));
        $compareResult = $compareResults[0];

        $statements = $generator->generate($compareResult);
        $sql = implode("\n", $statements);

        $this->assertStringContainsString('"created_at" TIMESTAMP NOT NULL', $sql);
        $this->assertStringContainsString('"updated_at" TIMESTAMP NOT NULL', $sql);
        $this->assertStringContainsString('"published_at" TIMESTAMP', $sql);

        foreach ($statements as $statement) {
            $connection->executeQuery($statement);
        }

        $columns = $connection->executeQuery(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_name = 'test_date_time_entity' AND table_schema = 'public'"
        )->fetchAll();

        $columnMap = array_column($columns, 'data_type', 'column_name');

        $this->assertStringStartsWith('timestamp', $columnMap['created_at']);
        $this->assertStringStartsWith('timestamp', $columnMap['updated_at']);
        $this->assertStringStartsWith('timestamp', $columnMap['published_at']);

        $connection->executeQuery('DROP TABLE IF EXISTS "test_date_time_entity"');
    }
}
