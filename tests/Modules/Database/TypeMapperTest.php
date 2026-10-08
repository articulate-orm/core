<?php

namespace Articulate\Tests\Modules\Database;

use Articulate\Modules\Database\MySqlTypeMapper;
use Articulate\Modules\Database\PostgresqlTypeMapper;
use Articulate\Utils\Point;
use Articulate\Utils\TypeRegistry;
use PHPUnit\Framework\TestCase;

class TypeMapperTest extends TestCase {
    public function testMySqlTypeMapperBasicTypes(): void
    {
        $mapper = new MySqlTypeMapper();

        $this->assertSame('INT', $mapper->getDatabaseType('int'));
        $this->assertSame('DOUBLE', $mapper->getDatabaseType('float'));
        $this->assertSame('VARCHAR(255)', $mapper->getDatabaseType('string'));
        $this->assertSame('TINYINT(1)', $mapper->getDatabaseType('bool'));
        $this->assertSame('TEXT', $mapper->getDatabaseType('mixed'));
    }

    public function testMySqlTypeMapperDateTimeTypes(): void
    {
        $mapper = new MySqlTypeMapper();

        $this->assertSame('DATETIME', $mapper->getDatabaseType('DateTime'));
        $this->assertSame('DATETIME', $mapper->getDatabaseType('DateTimeImmutable'));
        $this->assertSame('DATETIME', $mapper->getDatabaseType(\DateTimeInterface::class));
    }

    public function testMySqlTypeMapperSpatialTypes(): void
    {
        $mapper = new MySqlTypeMapper();

        $this->assertSame('POINT', $mapper->getDatabaseType(Point::class));
    }

    public function testMySqlTypeMapperTinyIntOneSpecialHandling(): void
    {
        $mapper = new MySqlTypeMapper();

        $this->assertSame('bool', $mapper->getPhpType('TINYINT(1)'));
        $this->assertSame('bool', $mapper->getPhpType('tinyint(1)'));
        $this->assertSame('int', $mapper->getPhpType('TINYINT(2)'));
    }

    public function testPostgresqlTypeMapperBasicTypes(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('INTEGER', $mapper->getDatabaseType('int'));
        $this->assertSame('DOUBLE PRECISION', $mapper->getDatabaseType('float'));
        $this->assertSame('VARCHAR(255)', $mapper->getDatabaseType('string'));
        $this->assertSame('BOOLEAN', $mapper->getDatabaseType('bool'));
        $this->assertSame('TEXT', $mapper->getDatabaseType('mixed'));
    }

    public function testPostgresqlTypeMapperDateTimeTypes(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('TIMESTAMP', $mapper->getDatabaseType('DateTime'));
        $this->assertSame('TIMESTAMP', $mapper->getDatabaseType('DateTimeImmutable'));
        $this->assertSame('TIMESTAMP', $mapper->getDatabaseType(\DateTimeInterface::class));
    }

    public function testPostgresqlTypeMapperPostgresqlSpecificTypes(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('UUID', $mapper->getDatabaseType('uuid'));
        $this->assertSame('JSONB', $mapper->getDatabaseType('json'));
    }

    public function testPostgresqlTypeMapperBooleanHandling(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('bool', $mapper->getPhpType('BOOLEAN'));
        $this->assertSame('bool', $mapper->getPhpType('boolean'));
        $this->assertSame('bool', $mapper->getPhpType('BOOL'));
    }

    public function testMySqlTypeMapperNullableTypes(): void
    {
        $mapper = new MySqlTypeMapper();

        $this->assertSame('INT', $mapper->getDatabaseType('?int'));
        $this->assertSame('VARCHAR(255)', $mapper->getDatabaseType('?string'));
        $this->assertSame('TINYINT(1)', $mapper->getDatabaseType('?bool'));
        $this->assertSame('POINT', $mapper->getDatabaseType('?' . Point::class));
    }

    public function testPostgresqlTypeMapperNullableTypes(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('INTEGER', $mapper->getDatabaseType('?int'));
        $this->assertSame('DOUBLE PRECISION', $mapper->getDatabaseType('?float'));
        $this->assertSame('VARCHAR(255)', $mapper->getDatabaseType('?string'));
        $this->assertSame('BOOLEAN', $mapper->getDatabaseType('?bool'));
    }

    public function testMySqlTypeMapperIntMapsToSignedByDefault(): void
    {
        $mapper = new MySqlTypeMapper();

        $this->assertSame('INT', $mapper->getDatabaseType('int'));
        $this->assertSame('INT', $mapper->getDatabaseType('?int'));
    }

    public function testMySqlTypeMapperInheritsFromTypeRegistry(): void
    {
        $mapper = new MySqlTypeMapper();
        $this->assertInstanceOf(TypeRegistry::class, $mapper);
    }

    public function testPostgresqlTypeMapperInheritsFromTypeRegistry(): void
    {
        $mapper = new PostgresqlTypeMapper();
        $this->assertInstanceOf(TypeRegistry::class, $mapper);
    }

    public function testMySqlTypeMapperCustomTypeRegistration(): void
    {
        $mapper = new MySqlTypeMapper();

        // Can still register custom types
        $mapper->registerType('custom', 'CUSTOM_TYPE');
        $this->assertSame('CUSTOM_TYPE', $mapper->getDatabaseType('custom'));
    }

    public function testPostgresqlTypeMapperCustomTypeRegistration(): void
    {
        $mapper = new PostgresqlTypeMapper();

        // Can still register custom types
        $mapper->registerType('custom', 'CUSTOM_TYPE');
        $this->assertSame('CUSTOM_TYPE', $mapper->getDatabaseType('custom'));
    }

    public function testMySqlTypeMapperUnknownTypesFallBack(): void
    {
        $mapper = new MySqlTypeMapper();

        // Unknown types should fall back to themselves
        $this->assertSame('UNKNOWN_TYPE', $mapper->getDatabaseType('UNKNOWN_TYPE'));
    }

    public function testPostgresqlTypeMapperUnknownTypesFallBack(): void
    {
        $mapper = new PostgresqlTypeMapper();

        // Unknown types should fall back to themselves
        $this->assertSame('UNKNOWN_TYPE', $mapper->getDatabaseType('UNKNOWN_TYPE'));
    }

    public function testPostgresqlTypeMapperInstantiation(): void
    {
        // Explicitly instantiate to ensure class coverage
        $mapper = new PostgresqlTypeMapper();
        $this->assertInstanceOf(PostgresqlTypeMapper::class, $mapper);
    }

    public function testPostgresqlTypeMapperUuidType(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('string', $mapper->getPhpType('UUID'));
        $this->assertSame('string', $mapper->getPhpType('uuid'));
    }

    public function testPostgresqlTypeMapperJsonTypes(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('mixed', $mapper->getPhpType('JSON'));
        $this->assertSame('mixed', $mapper->getPhpType('JSONB'));
        $this->assertSame('mixed', $mapper->getPhpType('json'));
        $this->assertSame('mixed', $mapper->getPhpType('jsonb'));
    }

    public function testPostgresqlTypeMapperSerialTypes(): void
    {
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('int', $mapper->getPhpType('SERIAL'));
        $this->assertSame('int', $mapper->getPhpType('BIGSERIAL'));
        $this->assertSame('int', $mapper->getPhpType('SMALLSERIAL'));
        $this->assertSame('int', $mapper->getPhpType('serial'));
    }

    public function testMySqlTypeMapperRegistersNonNullableDateTimeType(): void
    {
        // Covers MethodCallRemoval on registerType('DateTime', 'DATETIME') — the
        // non-nullable 'DateTime' key specifically (distinct from '?DateTime').
        $mapper = new MySqlTypeMapper();

        $this->assertSame('DATETIME', $mapper->getDatabaseType('DateTime'));
    }

    public function testMySqlTypeMapperRegistersNullableFloatType(): void
    {
        // Covers MethodCallRemoval on registerType('?float', 'DOUBLE').
        $mapper = new MySqlTypeMapper();

        $this->assertSame('DOUBLE', $mapper->getDatabaseType('?float'));
    }

    public function testMySqlTypeMapperGetPhpTypePrefersDbToPhpOverInference(): void
    {
        // Covers Coalesce operand-swap mutant: dbToPhp[baseType] must win over
        // inferPhpType(). 'POINT' is registered to Point::class via dbToPhp, but
        // inferPhpType() has no POINT arm and would fall back to 'mixed' — the
        // swapped coalesce would therefore return 'mixed' instead of Point::class.
        $mapper = new MySqlTypeMapper();

        $this->assertSame(Point::class, $mapper->getPhpType('POINT'));
    }

    public function testPostgresqlTypeMapperRegistersNullableDateTimeImmutableType(): void
    {
        // Covers MethodCallRemoval on registerType('?DateTimeImmutable', 'TIMESTAMP').
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('TIMESTAMP', $mapper->getDatabaseType('?DateTimeImmutable'));
    }

    public function testPostgresqlTypeMapperRegistersNullableJsonType(): void
    {
        // Covers MethodCallRemoval on registerType('?json', 'JSONB').
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('JSONB', $mapper->getDatabaseType('?json'));
    }

    public function testPostgresqlTypeMapperClassMappingUsesPriorityTen(): void
    {
        // Covers IncrementInteger mutant on the DateTimeInterface class-mapping
        // priority (10 -> 11): read the registered priority directly via
        // reflection on the private classMappings map, since DateTimeInterface
        // is the only interface DateTime implements that's registered, so we
        // can't force a losing competition through getDatabaseType() alone.
        $mapper = new PostgresqlTypeMapper();

        $reflection = new \ReflectionClass(TypeRegistry::class);
        $property = $reflection->getProperty('classMappings');
        $property->setAccessible(true);
        $classMappings = $property->getValue($mapper);

        $this->assertSame(10, $classMappings[\DateTimeInterface::class]['priority']);
    }

    public function testPostgresqlTypeMapperGetPhpTypePrefersDbToPhpOverInference(): void
    {
        // Covers Coalesce operand-swap mutant: dbToPhp['TIMESTAMP'] is registered
        // (last writer wins: 'DateTimeImmutable'), while inferPhpType('TIMESTAMP')
        // would independently return 'string' — the swapped coalesce would
        // surface 'string' instead.
        $mapper = new PostgresqlTypeMapper();

        $this->assertSame('DateTimeImmutable', $mapper->getPhpType('TIMESTAMP'));
    }
}
